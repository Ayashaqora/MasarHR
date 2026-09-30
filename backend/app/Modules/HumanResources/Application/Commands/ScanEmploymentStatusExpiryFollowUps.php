<?php

namespace App\Modules\HumanResources\Application\Commands;

use App\Modules\Audit\Application\AuditAppendService;
use App\Modules\Audit\Domain\AuditSpec;
use App\Modules\HumanResources\Domain\EmploymentStatusExpiryPolicy;
use App\Modules\HumanResources\Domain\StatusExpiryFollowUpEmission;
use App\Modules\HumanResources\Domain\StatusExpiryFollowUpScanResult;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\EmploymentRelationship;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\EmploymentStatusExpiryFollowUp;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\EmploymentStatusPeriod;
use App\Modules\Platform\Application\Clock\BusinessDateClock;
use App\Modules\Platform\Application\Execution\CommandContext;
use App\Modules\Platform\Domain\Actor;
use App\Modules\Platform\Domain\CorrelationId;
use App\Modules\Platform\Domain\Source;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * The safely repeatable Employment Status Expiry follow-up scan
 * (docs/employment-status-expiry-followup-specification.md §S38.10–§S38.13). LAZY: nothing is created when a
 * status is written (RecordEmploymentStatusPeriod is untouched). Callable from the scheduler command, tests and
 * any future worker — it holds ALL the rules; the scheduler definition holds none.
 *
 * One run, for one explicit business date D (default: the BusinessDateClock, never today()):
 *  1. RECONCILE — every already-emitted ACTIONABLE follow-up whose end has not been reached is rechecked; a stale
 *     one becomes SUPPRESSED with a stable reason (audited). One whose end has been reached is history (LAPSED,
 *     derived at read; never rewritten).
 *  2. DISCOVER + EMIT — every status period of an eligible code with an end E in (D, D + 7] that has no follow-up
 *     for (kind, period, E) yet is a candidate (one set-based statement; no scan of unbounded history). Each is
 *     rechecked under lock and only then inserted.
 *
 * Lock order everywhere: EmploymentRelationship row → EmploymentStatusPeriod row → follow-up row (the relationship
 * lock is the one every status/relationship command takes first, so a scan and those commands never interleave).
 * Emission is INSERT … SELECT from the authoritative period row with ON CONFLICT DO NOTHING against the PostgreSQL
 * logical-identity UNIQUE: retries and concurrent scans yield exactly one durable row, and only a REAL insert or
 * REAL transition writes an audit entry.
 *
 * The scanner NEVER mutates a status period, a relationship or any other HR timeline: no synthetic on_duty, no
 * movement change. Audit actor: Actor::system(SCHEDULER_STATUS_EXPIRY) with Source::System — provenance only.
 */
final class ScanEmploymentStatusExpiryFollowUps
{
    public const ACTOR_LABEL = 'SCHEDULER_STATUS_EXPIRY';

    public function __construct(
        private readonly AuditAppendService $audit,
        private readonly EmploymentStatusExpiryFollowUpRecheck $recheck,
        private readonly BusinessDateClock $clock,
    ) {}

    public function handle(?string $businessDate = null): StatusExpiryFollowUpScanResult
    {
        $date = $businessDate ?? $this->clock->today()->toDateString();

        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || ! checkdate((int) substr($date, 5, 2), (int) substr($date, 8, 2), (int) substr($date, 0, 4))) {
            throw new InvalidArgumentException('business date must be an explicit Y-m-d date.');
        }

        $context = new CommandContext(Actor::system(self::ACTOR_LABEL), CorrelationId::generate(), Source::System);

        $suppressed = [];
        foreach ($this->actionableToReconcile($date) as $row) {
            if ($this->reconcile($row->id, $row->employment_relationship_id, $row->employment_status_period_id, $date, $context)) {
                $suppressed[] = $row->id;
            }
        }

        $emitted = [];
        $alreadyExisting = 0;
        $notCreated = [];
        foreach ($this->candidates($date) as $candidate) {
            $emission = $this->emit($candidate->period_id, $candidate->employment_relationship_id, $candidate->expected_effective_to, $date, $context);

            match ($emission->outcome) {
                StatusExpiryFollowUpEmission::EMITTED => $emitted[] = $emission->followUpId,
                StatusExpiryFollowUpEmission::ALREADY_EXISTS => $alreadyExisting++,
                StatusExpiryFollowUpEmission::STALE => $notCreated[] = [
                    'employment_status_period_id' => $candidate->period_id,
                    'reason' => $emission->reason->value,
                ],
                default => null,
            };
        }

        return new StatusExpiryFollowUpScanResult($date, $emitted, $alreadyExisting, $suppressed, $notCreated);
    }

    /**
     * Emits ONE candidate: lock the relationship, then the period, reload and recheck the authoritative state, then
     * insert idempotently. Public so tests (and a future worker) can drive a single candidate, e.g. to prove a period
     * changed between discovery and emission is not emitted.
     */
    public function emit(
        string $periodId,
        string $relationshipId,
        string $expectedEffectiveTo,
        string $businessDate,
        CommandContext $context,
    ): StatusExpiryFollowUpEmission {
        return DB::transaction(function () use ($periodId, $relationshipId, $expectedEffectiveTo, $businessDate, $context): StatusExpiryFollowUpEmission {
            $relationship = EmploymentRelationship::query()->where('id', $relationshipId)->lockForUpdate()->firstOrFail();
            $period = EmploymentStatusPeriod::query()->where('id', $periodId)->where('employment_relationship_id', $relationshipId)->lockForUpdate()->firstOrFail();

            $code = (string) $period->statusDetail()->value('code');

            if (! EmploymentStatusExpiryPolicy::isEligible($code, $period->effective_to?->toDateString())) {
                return StatusExpiryFollowUpEmission::notEligible();
            }

            if (! EmploymentStatusExpiryPolicy::isInWindow($expectedEffectiveTo, $businessDate)) {
                return StatusExpiryFollowUpEmission::notDue();
            }

            $reason = $this->recheck->staleReason($period, $expectedEffectiveTo, $relationship);

            if ($reason !== null) {
                return StatusExpiryFollowUpEmission::stale($reason);
            }

            if ($period->effective_to->toDateString() !== $expectedEffectiveTo) {
                return StatusExpiryFollowUpEmission::notEligible();
            }

            $followUpId = (string) Str::uuid7();
            $inserted = DB::selectOne(
                'INSERT INTO automation.employment_status_expiry_followups
                    (id, followup_kind, employment_status_period_id, employment_relationship_id, expected_effective_to, due_date, status, created_at)
                 SELECT ?, ?, p.id, p.employment_relationship_id, p.effective_to, p.effective_to - '.EmploymentStatusExpiryPolicy::WARNING_LEAD_DAYS.", 'ACTIONABLE', now()
                 FROM hr.employment_status_periods p
                 WHERE p.id = ? AND p.employment_relationship_id = ? AND p.effective_to = CAST(? AS date)
                 ON CONFLICT ON CONSTRAINT employment_status_expiry_followups_logical_key DO NOTHING
                 RETURNING id",
                [$followUpId, EmploymentStatusExpiryPolicy::KIND, $periodId, $relationshipId, $expectedEffectiveTo],
            );

            if ($inserted === null) {
                return StatusExpiryFollowUpEmission::alreadyExists();
            }

            $row = EmploymentStatusExpiryFollowUp::query()->findOrFail($inserted->id);

            $this->audit->appendMutation($context, new AuditSpec(
                action: 'hr.employment_status_expiry_followup.emit',
                targetType: 'hr_employment_status_expiry_followup',
                targetId: fn (EmploymentStatusExpiryFollowUp $created) => $created->getKey(),
                changes: fn (EmploymentStatusExpiryFollowUp $created) => [
                    'followup_kind' => $created->followup_kind,
                    'employment_status_period_id' => $created->employment_status_period_id,
                    'employment_relationship_id' => $created->employment_relationship_id,
                    'expected_effective_to' => $created->expected_effective_to->toDateString(),
                    'due_date' => $created->due_date->toDateString(),
                    'status' => $created->status,
                ],
                metadata: fn () => ['business_date' => $businessDate],
            ), $row);

            return StatusExpiryFollowUpEmission::emitted($row->getKey());
        });
    }

    /**
     * Rechecks one ACTIONABLE follow-up and suppresses it when stale. Returns true only when this call performed
     * the ACTIONABLE → SUPPRESSED transition (and wrote its audit entry).
     */
    public function reconcile(string $followUpId, string $relationshipId, string $periodId, string $businessDate, CommandContext $context): bool
    {
        return DB::transaction(function () use ($followUpId, $relationshipId, $periodId, $businessDate, $context): bool {
            // Lock order everywhere: relationship row → status period row → follow-up row.
            $relationship = EmploymentRelationship::query()->where('id', $relationshipId)->lockForUpdate()->firstOrFail();
            $period = EmploymentStatusPeriod::query()->where('id', $periodId)->lockForUpdate()->firstOrFail();
            $row = EmploymentStatusExpiryFollowUp::query()->where('id', $followUpId)->lockForUpdate()->first();

            if ($row === null || $row->status !== EmploymentStatusExpiryFollowUp::ACTIONABLE) {
                return false;
            }

            $expected = $row->expected_effective_to->toDateString();

            // A follow-up whose end date has been reached is history: LAPSED is derived, never written.
            if ($expected <= $businessDate) {
                return false;
            }

            $reason = $this->recheck->staleReason($period, $expected, $relationship);

            if ($reason === null) {
                return false;
            }

            $currentEnd = $period->effective_to?->toDateString();

            DB::update(
                "UPDATE automation.employment_status_expiry_followups
                 SET status = 'SUPPRESSED', suppression_reason = ?, suppressed_at = now()
                 WHERE id = ? AND status = 'ACTIONABLE'",
                [$reason->value, $followUpId],
            );

            $this->audit->appendMutation($context, new AuditSpec(
                action: 'hr.employment_status_expiry_followup.suppress',
                targetType: 'hr_employment_status_expiry_followup',
                targetId: fn (EmploymentStatusExpiryFollowUp $suppressed) => $suppressed->getKey(),
                changes: fn () => ['status' => EmploymentStatusExpiryFollowUp::SUPPRESSED, 'suppression_reason' => $reason->value],
                metadata: fn (EmploymentStatusExpiryFollowUp $suppressed) => [
                    'followup_kind' => $suppressed->followup_kind,
                    'employment_status_period_id' => $suppressed->employment_status_period_id,
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

    /** ACTIONABLE follow-ups whose end date is still ahead — the only ones that can go stale. */
    private function actionableToReconcile(string $date)
    {
        return EmploymentStatusExpiryFollowUp::query()
            ->where('status', EmploymentStatusExpiryFollowUp::ACTIONABLE)
            ->where('expected_effective_to', '>', $date)
            ->orderBy('due_date')
            ->orderBy('id')
            ->get(['id', 'employment_relationship_id', 'employment_status_period_id']);
    }

    /**
     * Status periods of an eligible code whose end E lies in the actionable window (D, D + 7] and that have no
     * follow-up for their current end yet. ONE set-based statement; the predicate on effective_to is a plain range
     * (no expression on the column), so it does not depend on the amount of historical rows. Deterministic order.
     *
     * @return list<object{period_id: string, employment_relationship_id: string, expected_effective_to: string}>
     */
    private function candidates(string $date): array
    {
        $codes = implode(',', array_fill(0, count(EmploymentStatusExpiryPolicy::ELIGIBLE_CODES), '?'));

        return DB::select(
            'SELECT sp.id AS period_id, sp.employment_relationship_id, sp.effective_to AS expected_effective_to
             FROM hr.employment_status_periods sp
             JOIN ref.employment_status_details sd ON sd.id = sp.status_detail_id
             WHERE sp.effective_to IS NOT NULL
               AND sp.effective_to > CAST(? AS date)
               AND sp.effective_to <= CAST(? AS date) + '.EmploymentStatusExpiryPolicy::WARNING_LEAD_DAYS."
               AND sd.code IN ({$codes})
               AND NOT EXISTS (
                   SELECT 1 FROM automation.employment_status_expiry_followups f
                   WHERE f.followup_kind = ? AND f.employment_status_period_id = sp.id AND f.expected_effective_to = sp.effective_to)
             ORDER BY sp.effective_to, sp.id",
            [$date, $date, ...EmploymentStatusExpiryPolicy::ELIGIBLE_CODES, EmploymentStatusExpiryPolicy::KIND],
        );
    }
}
