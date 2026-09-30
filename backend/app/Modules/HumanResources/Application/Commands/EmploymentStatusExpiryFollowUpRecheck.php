<?php

namespace App\Modules\HumanResources\Application\Commands;

use App\Modules\HumanResources\Domain\StatusFollowUpSuppressionReason;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\EmploymentRelationship;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\EmploymentStatusPeriod;

/**
 * The mandatory stale-alert protection for status follow-ups
 * (docs/employment-status-expiry-followup-specification.md §S38.7–§S38.9): a due follow-up is never trusted — the
 * authoritative relationship and status period are RELOADED and the exact condition re-evaluated immediately
 * before a follow-up is emitted and again on every later scan of an already-emitted one.
 *
 * staleReason() returns null when the follow-up still describes reality, otherwise the stable reason. Checked in
 * this deterministic order (the root cause wins):
 *  1. RELATIONSHIP_ENDED — the relationship has a KNOWN end on or before the expected end (this also covers an
 *     end exactly at E). An UNKNOWN_LEGACY end is not a known end and is never treated as one.
 *  2. TRUNCATED_EARLIER — the period now ends EARLIER than expected (an ordinary truncation: a successor started
 *     before E). The old expected end is never rewritten; the old follow-up is suppressed.
 *  3. SUCCESSOR_RECORDED — an explicit status period of the same relationship starts exactly at the expected end.
 *
 * A period whose end is now null or LATER than expected cannot arise (no command extends or clears a recorded
 * end; S32 has no PATCH), so it yields null here and never a suppression reason S38 has no code for.
 * Callers hold the EmploymentRelationship row lock; nothing here writes.
 */
final class EmploymentStatusExpiryFollowUpRecheck
{
    public function staleReason(
        EmploymentStatusPeriod $period,
        string $expectedEffectiveTo,
        EmploymentRelationship $relationship,
    ): ?StatusFollowUpSuppressionReason {
        if ($relationship->end_knowledge_state === 'KNOWN'
            && $relationship->effective_to !== null
            && $relationship->effective_to->toDateString() <= $expectedEffectiveTo) {
            return StatusFollowUpSuppressionReason::RelationshipEnded;
        }

        $currentEnd = $period->effective_to?->toDateString();

        if ($currentEnd !== $expectedEffectiveTo) {
            return $currentEnd !== null && $currentEnd < $expectedEffectiveTo
                ? StatusFollowUpSuppressionReason::TruncatedEarlier
                : null;
        }

        return $this->successorStartsAt($period, $expectedEffectiveTo)
            ? StatusFollowUpSuppressionReason::SuccessorRecorded
            : null;
    }

    private function successorStartsAt(EmploymentStatusPeriod $period, string $end): bool
    {
        return EmploymentStatusPeriod::query()
            ->where('employment_relationship_id', $period->employment_relationship_id)
            ->where('id', '!=', $period->getKey())
            ->where('effective_from', $end)
            ->exists();
    }
}
