<?php

namespace App\Modules\HumanResources\Application\Commands;

use App\Modules\HumanResources\Domain\TemporaryMovementType;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\EmploymentRelationship;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\MovementExpiryFollowUp;
use Illuminate\Support\Facades\DB;

/**
 * S35 (Employment Relationship End Movement Integrity): the ONE implementation of what ending an
 * Employment Relationship at date E does to its Full Secondment and Workplace Assignment periods and
 * to their S31 expiry follow-ups. Used by EndEmploymentRelationship (so a direct end and a
 * terminal-status-induced end are identical) and by the two audit-snapshot call sites.
 *
 * For a movement M and end date E (half-open intervals):
 *  - CASE 1  M.from < E AND (M.to IS NULL OR M.to > E): truncated to end exactly at E (open and
 *            bounded alike — the relationship boundary does not distinguish them).
 *  - CASE 2  M.to <= E: historical, untouched.
 *  - CASE 3  M.from >= E: the row is KEPT EXACTLY AS RECORDED (never deleted, never given an empty
 *            interval, no new state or column). It can never become effective because the
 *            relationship ended on or before its start: every reader treats the relationship end as
 *            the effectiveness boundary.
 * Every ACTIONABLE follow-up of a Case 1 or Case 3 movement is SUPPRESSED (existing reason
 * RELATIONSHIP_ENDED) in the same transaction. Nothing here commits or opens a transaction; the
 * caller holds the EmploymentRelationship row lock (relationship first, then follow-up rows).
 */
final class RelationshipEndMovementConsequences
{
    /**
     * Read-only picture of the consequences an end at $effectiveTo will have, per movement stream.
     * Must be taken under the relationship lock (same as the caller's other snapshots).
     *
     * Keys: one entry per movement type (open_closed / bounded_truncated / future ids) and
     * 'actionable_followups' — the ACTIONABLE follow-up ids of the movements this end will truncate or
     * neutralize (exactly the ones the end will suppress).
     *
     * @return array<string, mixed>
     */
    public function snapshot(string $relationshipId, string $effectiveTo): array
    {
        $snapshot = ['actionable_followups' => []];

        foreach ($this->types() as $type) {
            $base = fn () => $type->modelClass()::query()->where('employment_relationship_id', $relationshipId);

            $snapshot[$type->value] = [
                'open_closed' => $base()->where('effective_from', '<', $effectiveTo)->whereNull('effective_to')->orderBy('id')->pluck('id')->all(),
                'bounded_truncated' => $base()->where('effective_from', '<', $effectiveTo)->where('effective_to', '>', $effectiveTo)->orderBy('id')->pluck('id')->all(),
                'future' => $base()->where('effective_from', '>=', $effectiveTo)->orderBy('id')->pluck('id')->all(),
            ];

            $affectedIds = [...$snapshot[$type->value]['open_closed'], ...$snapshot[$type->value]['bounded_truncated'], ...$snapshot[$type->value]['future']];

            if ($affectedIds !== []) {
                $snapshot['actionable_followups'] = [...$snapshot['actionable_followups'], ...MovementExpiryFollowUp::query()
                    ->where('employment_relationship_id', $relationshipId)
                    ->where('movement_type', $type->value)
                    ->whereIn('movement_id', $affectedIds)
                    ->where('status', MovementExpiryFollowUp::ACTIONABLE)
                    ->orderBy('id')->pluck('id')->all()];
            }
        }

        return $snapshot;
    }

    /** Applies Case 1 (truncate) and suppresses the affected ACTIONABLE follow-ups. Case 2 and Case 3 rows are not written. */
    public function apply(EmploymentRelationship $relationship, string $effectiveTo): void
    {
        $affected = [];

        foreach ($this->types() as $type) {
            $crossing = $type->modelClass()::query()
                ->where('employment_relationship_id', $relationship->getKey())
                ->where('effective_from', '<', $effectiveTo)
                ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>', $effectiveTo))
                ->get();

            foreach ($crossing as $movement) {
                $movement->update(['effective_to' => $effectiveTo]);
                $affected[$type->value][] = $movement->getKey();
            }

            $future = $type->modelClass()::query()
                ->where('employment_relationship_id', $relationship->getKey())
                ->where('effective_from', '>=', $effectiveTo)
                ->pluck('id')->all();

            $affected[$type->value] = [...($affected[$type->value] ?? []), ...$future];
        }

        foreach ($affected as $typeValue => $movementIds) {
            if ($movementIds === []) {
                continue;
            }

            $type = TemporaryMovementType::from($typeValue);

            DB::update(
                "UPDATE automation.movement_expiry_followups
                 SET status = 'SUPPRESSED', suppression_reason = 'RELATIONSHIP_ENDED', suppressed_at = now()
                 WHERE employment_relationship_id = ? AND movement_type = ? AND status = 'ACTIONABLE'
                   AND movement_id IN (".implode(',', array_fill(0, count($movementIds), '?')).')',
                [$relationship->getKey(), $type->value, ...$movementIds],
            );
        }
    }

    /**
     * Audit metadata for the relationship-end entry: technical identifiers only. Call AFTER the end
     * ran (inside the same transaction) with the snapshot taken before it.
     *
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>
     */
    public function metadata(string $relationshipId, array $snapshot): array
    {
        $metadata = [];

        foreach ($this->types() as $type) {
            $prefix = strtolower($type->value);
            $parts = $snapshot[$type->value];

            foreach (['open_closed' => $prefix.'_open_closed_ids', 'bounded_truncated' => $prefix.'_bounded_truncated_ids', 'future' => $prefix.'_future_neutralized_ids'] as $key => $metaKey) {
                if ($parts[$key] !== []) {
                    $metadata[$metaKey] = $parts[$key];
                }
            }
        }

        // Only the follow-ups that were ACTIONABLE before and are now SUPPRESSED by this end.
        $suppressed = $snapshot['actionable_followups'] === [] ? [] : MovementExpiryFollowUp::query()
            ->where('employment_relationship_id', $relationshipId)
            ->whereIn('id', $snapshot['actionable_followups'])
            ->where('status', MovementExpiryFollowUp::SUPPRESSED)
            ->where('suppression_reason', 'RELATIONSHIP_ENDED')
            ->orderBy('id')->pluck('id')->all();

        if ($suppressed !== []) {
            $metadata['movement_expiry_followups_suppressed_ids'] = $suppressed;
            $metadata['movement_expiry_followups_suppressed_count'] = count($suppressed);
        }

        return $metadata;
    }

    /** @return list<TemporaryMovementType> */
    private function types(): array
    {
        return [TemporaryMovementType::FullSecondment, TemporaryMovementType::WorkplaceAssignment];
    }
}
