<?php

namespace App\Modules\HumanResources\Application\Commands;

use App\Modules\HumanResources\Domain\Exceptions\EmploymentRelationshipAlreadyEndedException;
use App\Modules\HumanResources\Domain\Exceptions\InvalidEmploymentCategoryException;
use App\Modules\HumanResources\Domain\Exceptions\InvalidEmploymentCategoryPeriodDateException;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\EmploymentCategoryPeriod;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\EmploymentRelationship;
use App\Modules\Platform\Infrastructure\Persistence\Postgres\PostgresErrorClassifier as Errors;
use App\Modules\Reference\Infrastructure\Persistence\Eloquent\EmploymentCategory;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;

/**
 * Records one employment-category period for an Employment Relationship
 * (docs/employment-category-history-foundation-specification.md §S20.9, ADR-S20-001). Same
 * auto-close-on-insert, contiguous-history shape as S11's RecordOrganizationalPlacementPeriod: the
 * currently-open period (if any) is closed at exactly the new period's effective_from, then the
 * new period is inserted open-ended. No downstream consequence step — recording a category never
 * closes, reopens, or otherwise mutates the Employment Relationship it belongs to.
 *
 * The EmploymentRelationship is re-fetched fresh with lockForUpdate() as the first statement here
 * — never trusted from whatever the caller passed in — exactly mirroring every S10–S16 command's
 * established discipline. This serializes this command against a concurrent
 * EndEmploymentRelationship (whose scoped UPDATE takes the same row lock) or
 * RecordEmploymentCategoryPeriod on the same relationship; the employment_category_periods_no_overlap
 * EXCLUDE constraint remains the database-level backstop independent of that lock.
 *
 * Unlike S10/S11 (which apply no `is_active` gate to their targets), ADR-S20-001 §6 explicitly
 * requires the referenced ref.employment_categories row to be active at command time for a NEW
 * assignment. The category is re-fetched fresh here, inside the transaction, rather than trusted
 * from the controller's earlier lookup.
 */
final class RecordEmploymentCategoryPeriod
{
    /**
     * @throws EmploymentRelationshipAlreadyEndedException|InvalidEmploymentCategoryException
     * @throws InvalidEmploymentCategoryPeriodDateException
     */
    public function handle(
        EmploymentRelationship $relationship,
        EmploymentCategory $category,
        string $effectiveFrom,
    ): EmploymentCategoryPeriod {
        $freshRelationship = EmploymentRelationship::query()
            ->where('id', $relationship->getKey())
            ->lockForUpdate()
            ->firstOrFail();

        // An ended relationship's category history is closed too (ADR-S20-001 §5) — no
        // reactivation, mirroring S10/S11 exactly.
        if ($freshRelationship->end_knowledge_state === 'KNOWN') {
            throw new EmploymentRelationshipAlreadyEndedException;
        }

        $freshCategory = EmploymentCategory::query()
            ->where('id', $category->getKey())
            ->first();

        if ($freshCategory === null || ! $freshCategory->is_active) {
            throw new InvalidEmploymentCategoryException;
        }

        $newFrom = Carbon::parse($effectiveFrom);

        // CA-01 (ADR-S20-001, S20 only): a category MAY begin on exactly the relationship's own
        // effective_from ("day-one category") but NEVER before it — deliberately NOT the strict
        // "after" rule S10/S11/S12/S16 use, which remain unchanged. effective_from is set exactly
        // once, at CreateEmploymentRelationship, and is never mutated afterward by any command in
        // this codebase — comparing against it here carries no concurrency risk despite being an
        // application-level check rather than a database constraint (same disclosure as S10 spec
        // §7.3 / S11 spec §8 step 3).
        if ($newFrom->lt($freshRelationship->effective_from)) {
            throw new InvalidEmploymentCategoryPeriodDateException;
        }

        // Compared against the LATEST recorded period (open or not), not merely the open one: a
        // backdated period may never be inserted before or on top of later history (spec
        // §S20.9). Under command discipline the latest period is always the open one; checking
        // the latest regardless keeps the rule correct even for history that does not end in an
        // open row. The relationship row lock above makes this read race-free against every
        // other writer of this table.
        $latestPeriod = EmploymentCategoryPeriod::query()
            ->where('employment_relationship_id', $freshRelationship->getKey())
            ->orderByDesc('effective_from')
            ->first();

        if ($latestPeriod !== null && $newFrom->lte($latestPeriod->effective_from)) {
            throw new InvalidEmploymentCategoryPeriodDateException;
        }

        if ($latestPeriod !== null && $latestPeriod->effective_to === null) {
            try {
                $latestPeriod->update(['effective_to' => $effectiveFrom]);
            } catch (QueryException $e) {
                if (Errors::isCheckViolation($e)) {
                    throw new InvalidEmploymentCategoryPeriodDateException;
                }

                throw $e;
            }
        }

        $period = new EmploymentCategoryPeriod([
            'employment_relationship_id' => $freshRelationship->getKey(),
            'employment_category_id' => $freshCategory->getKey(),
            'effective_from' => $effectiveFrom,
            'effective_to' => null,
        ]);

        try {
            $period->save();
        } catch (QueryException $e) {
            if (Errors::isExclusionViolation($e) || Errors::isCheckViolation($e)) {
                throw new InvalidEmploymentCategoryPeriodDateException;
            }

            throw $e;
        }

        return $period->refresh();
    }
}
