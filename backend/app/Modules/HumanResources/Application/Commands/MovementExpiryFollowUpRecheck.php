<?php

namespace App\Modules\HumanResources\Application\Commands;

use App\Modules\HumanResources\Domain\FollowUpSuppressionReason;
use App\Modules\HumanResources\Domain\TemporaryMovementType;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\EmploymentRelationship;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\FullSecondmentPeriod;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\PartialSecondmentPeriod;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\WorkplaceAssignmentPeriod;
use Illuminate\Database\Eloquent\Builder;

/**
 * The mandatory stale-alert protection (docs/movement-expiry-followup-foundation-specification.md
 * §S31.7, ADR-S31-005/006): a due follow-up is never trusted — the authoritative movement and
 * relationship are RELOADED and the exact due condition re-evaluated, immediately before a
 * follow-up is emitted and again on every later scan of an already-emitted one.
 *
 * staleReason() returns null when the follow-up still describes reality, otherwise the stable
 * reason. Checked in this order (the root cause wins):
 *  1. RELATIONSHIP_ENDED — the Employment Relationship has a KNOWN end on or before the expected
 *     end, so the movement ends because employment ends: there is no return to act on. An
 *     UNKNOWN_LEGACY end is not a known end and is never treated as one.
 *  2. TRUNCATED_EARLIER / END_DATE_CHANGED — the movement's current effective_to is no longer the
 *     expected one (a newer movement, a transfer or a relationship end truncated it; or it
 *     changed some other way). The new end date is its OWN, independent logical follow-up.
 *  3. COVERED_BY_NEWER_MOVEMENT — the expected end is covered by another movement, so no return to
 *     the underlying workplace happens then (an extension is a NEW period, never an overwrite):
 *     for a Full Secondment or Workplace Assignment, any Full Secondment or Workplace Assignment
 *     effective on that date; for a Partial Secondment, likewise, or other Partial Secondments
 *     effective on that date that together allocate every weekday of this one.
 *
 * The movement row can never have been deleted (FK RESTRICT, append-only history), so "movement
 * not found" is structurally impossible and has no code path. Callers hold the
 * EmploymentRelationship row lock; nothing here writes.
 */
final class MovementExpiryFollowUpRecheck
{
    public function staleReason(
        TemporaryMovementType $type,
        string $movementId,
        string $expectedEffectiveTo,
        EmploymentRelationship $relationship,
    ): ?FollowUpSuppressionReason {
        if ($relationship->end_knowledge_state === 'KNOWN'
            && $relationship->effective_to !== null
            && $relationship->effective_to->toDateString() <= $expectedEffectiveTo) {
            return FollowUpSuppressionReason::RelationshipEnded;
        }

        $movement = $type->modelClass()::query()->find($movementId);
        $currentEnd = $movement?->effective_to?->toDateString();

        if ($currentEnd !== $expectedEffectiveTo) {
            return $currentEnd !== null && $currentEnd < $expectedEffectiveTo
                ? FollowUpSuppressionReason::TruncatedEarlier
                : FollowUpSuppressionReason::EndDateChanged;
        }

        return $this->coveredByNewerMovement($type, $movement, $expectedEffectiveTo)
            ? FollowUpSuppressionReason::CoveredByNewerMovement
            : null;
    }

    private function coveredByNewerMovement(TemporaryMovementType $type, FullSecondmentPeriod|WorkplaceAssignmentPeriod|PartialSecondmentPeriod $movement, string $end): bool
    {
        $relationshipId = $movement->employment_relationship_id;
        $effectiveOn = fn (Builder $q) => $q
            ->where('employment_relationship_id', $relationshipId)
            ->where('id', '!=', $movement->getKey())
            ->where('effective_from', '<=', $end)
            ->where(fn ($w) => $w->whereNull('effective_to')->orWhere('effective_to', '>', $end));

        // A whole-workplace movement (Full Secondment / Workplace Assignment) effective on the
        // expected end date means the employee does not return to the underlying workplace then.
        if (FullSecondmentPeriod::query()->tap($effectiveOn)->exists()
            || WorkplaceAssignmentPeriod::query()->tap($effectiveOn)->exists()) {
            return true;
        }

        if ($type !== TemporaryMovementType::PartialSecondment) {
            return false;
        }

        $allocated = PartialSecondmentPeriod::query()->tap($effectiveOn)->with('weekdays')->get()
            ->flatMap(fn (PartialSecondmentPeriod $other) => $other->weekdayCodes())->unique()->all();

        return array_diff($movement->weekdayCodes(), $allocated) === [];
    }
}
