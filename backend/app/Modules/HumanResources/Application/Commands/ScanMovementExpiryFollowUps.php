<?php

namespace App\Modules\HumanResources\Application\Commands;

use App\Modules\Audit\Application\AuditAppendService;
use App\Modules\Audit\Domain\AuditSpec;
use App\Modules\HumanResources\Domain\ExpiryFollowUpEmission;
use App\Modules\HumanResources\Domain\ExpiryFollowUpScanResult;
use App\Modules\HumanResources\Domain\MovementExpiryPolicy;
use App\Modules\HumanResources\Domain\TemporaryMovementType;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\EmploymentRelationship;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\MovementExpiryFollowUp;
use App\Modules\Platform\Application\Clock\BusinessDateClock;
use App\Modules\Platform\Application\Execution\CommandContext;
use App\Modules\Platform\Domain\Actor;
use App\Modules\Platform\Domain\CorrelationId;
use App\Modules\Platform\Domain\Source;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * The safely repeatable Movement Expiry follow-up scan
 * (docs/movement-expiry-followup-foundation-specification.md §S31.5–§S31.11, ADR-S31-003…018).
 * Callable from the scheduler command, from tests, and from any future worker — it holds ALL the
 * rules; the scheduler definition holds none.
 *
 * One run, for one explicit business date D (default: the BusinessDateClock, never today()):
 *  1. RECONCILE — every already-emitted ACTIONABLE follow-up whose movement has not ended yet is
 *     rechecked; a stale one becomes SUPPRESSED with a stable reason (audited).
 *  2. DISCOVER + EMIT — every bounded temporary movement (Full Secondment, Workplace Assignment,
 *     Partial Secondment period) with E − 7 <= D < E, that has no follow-up for (kind, movement,
 *     E) yet, is a candidate. Open-ended movements (no end date) are never candidates, and a
 *     movement that already ended (E <= D) is history — no retroactive alert (ADR-S31-017).
 *
 * Every write for a relationship happens in one transaction under that relationship's row lock —
 * the same lock every movement command takes (S10–S30) — so a movement command and a scan can never
 * interleave. Emission is INSERT … SELECT from the authoritative movement row (so the copied ids and
 * dates cannot drift) with ON CONFLICT DO NOTHING against the PostgreSQL logical-identity UNIQUE
 * constraint: scheduler retries and concurrent scans yield exactly one durable row, and only a
 * REAL insert or REAL transition writes an audit entry (a scan that finds nothing writes none).
 *
 * The scanner NEVER mutates a movement, placement or any HR timeline (ADR-S31-018) — it creates and
 * transitions follow-up rows only. Automatic return is a derived effective state, not a write.
 * Audit actor: Actor::system(SCHEDULER_MOVEMENT_EXPIRY) with Source::System — the S04 provision
 * "kept in the enum for forward extensibility"; it is provenance only and grants no authority.
 */
final class ScanMovementExpiryFollowUps
{
    public const ACTOR_LABEL = 'SCHEDULER_MOVEMENT_EXPIRY';

    public function __construct(
        private readonly AuditAppendService $audit,
        private readonly MovementExpiryFollowUpRecheck $recheck,
        private readonly BusinessDateClock $clock,
    ) {}

    public function handle(?string $businessDate = null): ExpiryFollowUpScanResult
    {
        $date = $businessDate ?? $this->clock->today()->toDateString();

        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || ! checkdate((int) substr($date, 5, 2), (int) substr($date, 8, 2), (int) substr($date, 0, 4))) {
            throw new InvalidArgumentException('business date must be an explicit Y-m-d date.');
        }

        $context = new CommandContext(Actor::system(self::ACTOR_LABEL), CorrelationId::generate(), Source::System);

        $suppressed = [];
        foreach ($this->actionableToReconcile($date) as $row) {
            if ($this->reconcile($row->id, $row->employment_relationship_id, $date, $context)) {
                $suppressed[] = $row->id;
            }
        }

        $emitted = [];
        $alreadyExisting = 0;
        $stale = [];
        foreach ($this->candidates($date) as $candidate) {
            $emission = $this->emit(
                TemporaryMovementType::from($candidate->movement_type),
                $candidate->movement_id,
                $candidate->employment_relationship_id,
                $candidate->expected_effective_to,
                $date,
                $context,
            );

            match ($emission->outcome) {
                ExpiryFollowUpEmission::EMITTED => $emitted[] = $emission->followUpId,
                ExpiryFollowUpEmission::ALREADY_EXISTS => $alreadyExisting++,
                ExpiryFollowUpEmission::STALE => $stale[] = [
                    'movement_type' => $candidate->movement_type,
                    'movement_id' => $candidate->movement_id,
                    'reason' => $emission->reason->value,
                ],
                default => null,
            };
        }

        return new ExpiryFollowUpScanResult($date, $emitted, $alreadyExisting, $suppressed, $stale);
    }

    /**
     * Emits ONE candidate: lock the relationship, reload and recheck the authoritative movement,
     * then insert idempotently. Public so tests (and a future worker) can drive a single candidate,
     * e.g. to prove a movement changed between discovery and emission is suppressed, not emitted.
     */
    public function emit(
        TemporaryMovementType $type,
        string $movementId,
        string $relationshipId,
        string $expectedEffectiveTo,
        string $businessDate,
        CommandContext $context,
    ): ExpiryFollowUpEmission {
        return DB::transaction(function () use ($type, $movementId, $relationshipId, $expectedEffectiveTo, $businessDate, $context): ExpiryFollowUpEmission {
            $relationship = EmploymentRelationship::query()->where('id', $relationshipId)->lockForUpdate()->firstOrFail();

            if (! MovementExpiryPolicy::isInWindow($expectedEffectiveTo, $businessDate)) {
                return ExpiryFollowUpEmission::notDue();
            }

            $reason = $this->recheck->staleReason($type, $movementId, $expectedEffectiveTo, $relationship);

            if ($reason !== null) {
                return ExpiryFollowUpEmission::stale($reason);
            }

            $followUpId = (string) Str::uuid7();
            $inserted = DB::selectOne(
                "INSERT INTO automation.movement_expiry_followups
                    (id, followup_kind, movement_type, {$type->followUpColumn()}, employment_relationship_id, organizational_unit_id,
                     expected_effective_to, due_date, status, created_at)
                 SELECT ?, ?, ?, m.id, m.employment_relationship_id, m.organizational_unit_id,
                        m.effective_to, m.effective_to - ".MovementExpiryPolicy::WARNING_LEAD_DAYS.", 'ACTIONABLE', now()
                 FROM {$type->table()} m
                 WHERE m.id = ? AND m.employment_relationship_id = ? AND m.effective_to = CAST(? AS date)
                 ON CONFLICT ON CONSTRAINT movement_expiry_followups_logical_key DO NOTHING
                 RETURNING id",
                [$followUpId, MovementExpiryPolicy::KIND, $type->value, $movementId, $relationshipId, $expectedEffectiveTo],
            );

            if ($inserted === null) {
                return ExpiryFollowUpEmission::alreadyExists();
            }

            $row = MovementExpiryFollowUp::query()->findOrFail($inserted->id);

            $this->audit->appendMutation($context, new AuditSpec(
                action: 'hr.movement_expiry_followup.emit',
                targetType: 'hr_movement_expiry_followup',
                targetId: fn (MovementExpiryFollowUp $created) => $created->getKey(),
                changes: fn (MovementExpiryFollowUp $created) => [
                    'followup_kind' => $created->followup_kind,
                    'movement_type' => $created->movement_type,
                    'movement_id' => $created->movement_id,
                    'employment_relationship_id' => $created->employment_relationship_id,
                    'organizational_unit_id' => $created->organizational_unit_id,
                    'expected_effective_to' => $created->expected_effective_to->toDateString(),
                    'due_date' => $created->due_date->toDateString(),
                    'status' => $created->status,
                ],
                metadata: fn () => ['business_date' => $businessDate],
            ), $row);

            return ExpiryFollowUpEmission::emitted($row->getKey());
        });
    }

    /**
     * Rechecks one ACTIONABLE follow-up and suppresses it when stale. Returns true only when this
     * call performed the ACTIONABLE → SUPPRESSED transition (and wrote its audit entry).
     */
    public function reconcile(string $followUpId, string $relationshipId, string $businessDate, CommandContext $context): bool
    {
        return DB::transaction(function () use ($followUpId, $relationshipId, $businessDate, $context): bool {
            // Lock order everywhere: relationship row first, then the follow-up row.
            $relationship = EmploymentRelationship::query()->where('id', $relationshipId)->lockForUpdate()->firstOrFail();
            $row = MovementExpiryFollowUp::query()->where('id', $followUpId)->lockForUpdate()->first();

            if ($row === null || $row->status !== MovementExpiryFollowUp::ACTIONABLE) {
                return false;
            }

            $expected = $row->expected_effective_to->toDateString();

            // A follow-up whose end date has been reached is history: the movement ended as planned.
            if ($expected <= $businessDate) {
                return false;
            }

            $type = $row->movementType();
            $reason = $this->recheck->staleReason($type, $row->movement_id, $expected, $relationship);

            if ($reason === null) {
                return false;
            }

            $currentEnd = $type->modelClass()::query()->find($row->movement_id)?->effective_to?->toDateString();

            DB::update(
                "UPDATE automation.movement_expiry_followups
                 SET status = 'SUPPRESSED', suppression_reason = ?, suppressed_at = now()
                 WHERE id = ? AND status = 'ACTIONABLE'",
                [$reason->value, $followUpId],
            );

            $this->audit->appendMutation($context, new AuditSpec(
                action: 'hr.movement_expiry_followup.suppress',
                targetType: 'hr_movement_expiry_followup',
                targetId: fn (MovementExpiryFollowUp $suppressed) => $suppressed->getKey(),
                changes: fn () => ['status' => MovementExpiryFollowUp::SUPPRESSED, 'suppression_reason' => $reason->value],
                metadata: fn (MovementExpiryFollowUp $suppressed) => [
                    'followup_kind' => $suppressed->followup_kind,
                    'movement_type' => $suppressed->movement_type,
                    'movement_id' => $suppressed->movement_id,
                    'employment_relationship_id' => $suppressed->employment_relationship_id,
                    'expected_effective_to' => $expected,
                    'due_date' => $suppressed->due_date->toDateString(),
                    'current_effective_to' => $currentEnd,
                    'business_date' => $businessDate,
                ],
            ), $row->refresh());

            return true;
        });
    }

    /** ACTIONABLE follow-ups whose movement end date is still ahead — the only ones that can go stale. */
    private function actionableToReconcile(string $date)
    {
        return MovementExpiryFollowUp::query()
            ->where('status', MovementExpiryFollowUp::ACTIONABLE)
            ->where('expected_effective_to', '>', $date)
            ->orderBy('due_date')
            ->orderBy('id')
            ->get(['id', 'employment_relationship_id']);
    }

    /**
     * Bounded temporary movements in the actionable window that have no follow-up for their
     * current end date yet. ONE statement for the three streams; order is deterministic.
     *
     * @return list<object{movement_type: string, movement_id: string, employment_relationship_id: string, expected_effective_to: string}>
     */
    private function candidates(string $date): array
    {
        $lead = MovementExpiryPolicy::WARNING_LEAD_DAYS;
        $parts = [];
        $bindings = [];

        foreach (TemporaryMovementType::cases() as $type) {
            $parts[] = "SELECT '{$type->value}' AS movement_type, m.id AS movement_id, m.employment_relationship_id,
                               m.effective_to AS expected_effective_to
                        FROM {$type->table()} m
                        WHERE m.effective_to IS NOT NULL
                          AND m.effective_to - {$lead} <= CAST(? AS date) AND CAST(? AS date) < m.effective_to
                          AND NOT EXISTS (
                              SELECT 1 FROM automation.movement_expiry_followups f
                              WHERE f.followup_kind = '".MovementExpiryPolicy::KIND."' AND f.movement_type = '{$type->value}'
                                AND f.movement_id = m.id AND f.expected_effective_to = m.effective_to)";
            array_push($bindings, $date, $date);
        }

        return DB::select(implode(' UNION ALL ', $parts).' ORDER BY expected_effective_to, movement_id', $bindings);
    }
}
