<?php

namespace App\Modules\HumanResources\Application\Commands;

use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\FullSecondmentPeriod;
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
 * Callers MUST hold the EmploymentRelationship row lock (the S10–S27 discipline) and run inside
 * a transaction, so validation, truncation and the caller's own insert commit or roll back
 * together.
 */
final class SupersedeTemporaryWorkplaceMovement
{
    /**
     * The period of the given stream effective at $date, or null.
     *
     * @param  class-string<FullSecondmentPeriod|WorkplaceAssignmentPeriod>  $modelClass
     */
    public function effectiveAt(string $modelClass, string $relationshipId, string $date): FullSecondmentPeriod|WorkplaceAssignmentPeriod|null
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
     * @param  class-string<FullSecondmentPeriod|WorkplaceAssignmentPeriod>  $modelClass
     * @param  callable(): RuntimeException  $conflict
     */
    public function supersedeAt(string $modelClass, string $relationshipId, string $date, callable $conflict): FullSecondmentPeriod|WorkplaceAssignmentPeriod|null
    {
        $this->assertSupersedable($modelClass, $relationshipId, $date, $conflict);

        $effective = $this->effectiveAt($modelClass, $relationshipId, $date);

        return $effective === null ? null : $this->truncate($effective, $date, $conflict);
    }

    /**
     * Throws the conflict when superseding at $date would require rewriting later history or
     * erasing a period that starts on $date. Mutates nothing.
     *
     * @param  class-string<FullSecondmentPeriod|WorkplaceAssignmentPeriod>  $modelClass
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
     * Ends $period exactly at $date. The period must be effective at $date and start before it.
     *
     * @param  callable(): RuntimeException  $conflict
     */
    public function truncate(FullSecondmentPeriod|WorkplaceAssignmentPeriod $period, string $date, callable $conflict): FullSecondmentPeriod|WorkplaceAssignmentPeriod
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
