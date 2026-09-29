<?php

namespace App\Modules\HumanResources\Application\Commands;

use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\FullSecondmentPeriod;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\PartialSecondmentPeriod;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\WorkplaceAssignmentPeriod;
use App\Modules\Platform\Infrastructure\Persistence\Postgres\PostgresErrorClassifier as Errors;
use Illuminate\Database\QueryException;
use RuntimeException;

/**
 * ADR-S28-001 (docs/movement-temporal-integrity-corrective-specification.md §S28.4): the one
 * INTERVAL-AWARE rule for a temporary workplace movement stream (S12 Full Secondment or S16
 * Workplace Assignment) when something new takes effect at business date D.
 *
 * "Effective at D" is the half-open test effective_from <= D AND (effective_to IS NULL OR
 * D < effective_to) — never "open row": a period recorded with a FUTURE effective_to is still
 * effective at D. Adjacency (effective_to = D) is not a conflict.
 *
 * supersedeAt(): the period effective at D (at most one per stream — each table carries its own
 * EXCLUDE constraint) is TRUNCATED to end exactly at D. It refuses — throwing the caller-supplied
 * conflict — instead of rewriting history when:
 *  - a period of this stream STARTS after D (the new open-ended movement would have to rewrite
 *    already-recorded later history — S28 is not a general historical editor), or
 *  - the period effective at D itself starts on D (truncating it would erase it).
 * Periods that already ended on or before D are never touched. Nothing is deleted, and identity,
 * unit and effective_from of the truncated period are preserved.
 *
 * S30 (docs/partial-secondment-foundation-specification.md §S30.12, ADR-S30-007): the same rule
 * also serves the Partial Secondment stream in its interaction with Workplace Assignment ONLY.
 * Partial Secondments of one relationship may legitimately overlap each other (disjoint weekdays),
 * so more than one can be effective at D: effectiveAllAt() / supersedeAllAt() handle that stream.
 * It is never used between Partial Secondments, nor between Full and Partial Secondment
 * (ADR-S30-006: those reject).
 *
 * Callers MUST hold the EmploymentRelationship row lock (the S10–S27 discipline) and run inside
 * a transaction, so validation, truncation and the caller's own insert commit or roll back
 * together.
 */
final class SupersedeTemporaryWorkplaceMovement
{
    /**
     * The period of the given stream effective at $date, or null.
     *
     * @param  class-string<FullSecondmentPeriod|PartialSecondmentPeriod|WorkplaceAssignmentPeriod>  $modelClass
     */
    public function effectiveAt(string $modelClass, string $relationshipId, string $date): FullSecondmentPeriod|PartialSecondmentPeriod|WorkplaceAssignmentPeriod|null
    {
        return $modelClass::query()
            ->where('employment_relationship_id', $relationshipId)
            ->where('effective_from', '<=', $date)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>', $date))
            ->first();
    }

    /**
     * Validates, then truncates the period effective at $date. Returns the truncated period
     * (fresh), or null when nothing of this stream is effective at $date.
     *
     * @param  class-string<FullSecondmentPeriod|PartialSecondmentPeriod|WorkplaceAssignmentPeriod>  $modelClass
     * @param  callable(): RuntimeException  $conflict
     */
    public function supersedeAt(string $modelClass, string $relationshipId, string $date, callable $conflict): FullSecondmentPeriod|PartialSecondmentPeriod|WorkplaceAssignmentPeriod|null
    {
        $this->assertSupersedable($modelClass, $relationshipId, $date, $conflict);

        $effective = $this->effectiveAt($modelClass, $relationshipId, $date);

        return $effective === null ? null : $this->truncate($effective, $date, $conflict);
    }

    /**
     * Throws the conflict when superseding at $date would require rewriting later history or
     * erasing a period that starts on $date. Mutates nothing.
     *
     * @param  class-string<FullSecondmentPeriod|PartialSecondmentPeriod|WorkplaceAssignmentPeriod>  $modelClass
     * @param  callable(): RuntimeException  $conflict
     */
    public function assertSupersedable(string $modelClass, string $relationshipId, string $date, callable $conflict): void
    {
        $startsOnOrAfter = $modelClass::query()
            ->where('employment_relationship_id', $relationshipId)
            ->where('effective_from', '>=', $date)
            ->exists();

        if ($startsOnOrAfter) {
            throw $conflict();
        }
    }

    /**
     * Every period of the given stream effective at $date (S30: Partial Secondments may overlap).
     *
     * @param  class-string<FullSecondmentPeriod|PartialSecondmentPeriod|WorkplaceAssignmentPeriod>  $modelClass
     * @return list<FullSecondmentPeriod|PartialSecondmentPeriod|WorkplaceAssignmentPeriod>
     */
    public function effectiveAllAt(string $modelClass, string $relationshipId, string $date): array
    {
        return $modelClass::query()
            ->where('employment_relationship_id', $relationshipId)
            ->where('effective_from', '<=', $date)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>', $date))
            ->orderBy('effective_from')
            ->orderBy('id')
            ->get()
            ->all();
    }

    /**
     * supersedeAt() for a stream that may have several periods effective at $date: validates, then
     * truncates every one of them at $date. Returns the truncated periods (fresh).
     *
     * @param  class-string<FullSecondmentPeriod|PartialSecondmentPeriod|WorkplaceAssignmentPeriod>  $modelClass
     * @param  callable(): RuntimeException  $conflict
     * @return list<FullSecondmentPeriod|PartialSecondmentPeriod|WorkplaceAssignmentPeriod>
     */
    public function supersedeAllAt(string $modelClass, string $relationshipId, string $date, callable $conflict): array
    {
        $this->assertSupersedable($modelClass, $relationshipId, $date, $conflict);

        return array_map(
            fn ($period) => $this->truncate($period, $date, $conflict),
            $this->effectiveAllAt($modelClass, $relationshipId, $date),
        );
    }

    /**
     * Ends $period exactly at $date. The period must be effective at $date and start before it.
     *
     * @param  callable(): RuntimeException  $conflict
     */
    public function truncate(FullSecondmentPeriod|PartialSecondmentPeriod|WorkplaceAssignmentPeriod $period, string $date, callable $conflict): FullSecondmentPeriod|PartialSecondmentPeriod|WorkplaceAssignmentPeriod
    {
        if ($period->effective_from->toDateString() >= $date) {
            throw $conflict();
        }

        try {
            $period->update(['effective_to' => $date]);
        } catch (QueryException $e) {
            if (Errors::isCheckViolation($e)) {
                throw $conflict();
            }

            throw $e;
        }

        return $period->refresh();
    }
}
