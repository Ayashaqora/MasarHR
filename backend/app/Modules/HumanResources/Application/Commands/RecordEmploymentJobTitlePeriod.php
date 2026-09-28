<?php

namespace App\Modules\HumanResources\Application\Commands;

use App\Modules\HumanResources\Domain\Exceptions\EmploymentRelationshipAlreadyEndedException;
use App\Modules\HumanResources\Domain\Exceptions\InvalidEmploymentJobTitleException;
use App\Modules\HumanResources\Domain\Exceptions\InvalidEmploymentJobTitlePeriodDateException;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\EmploymentJobTitlePeriod;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\EmploymentRelationship;
use App\Modules\Platform\Infrastructure\Persistence\Postgres\PostgresErrorClassifier as Errors;
use App\Modules\Reference\Infrastructure\Persistence\Eloquent\JobTitle;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;

/**
 * Records one employment job title period — the first known title or a later title change — for an
 * Employment Relationship of either appointment type
 * (docs/employment-job-title-history-foundation-specification.md §S22.9, ADR-S22-001). Same
 * auto-close-on-insert discipline as S20's RecordEmploymentCategoryPeriod: when the latest period
 * is still open it is TEMPORALLY CLOSED at exactly the new period's effective_from (its identity,
 * job title and effective_from are preserved — temporal closure, not historical replacement), then
 * the new period is inserted open-ended. A latest period that is already closed (e.g. imported
 * history with a genuine gap) is left untouched, so the gap stays visible. Recording a job title
 * has no downstream consequence: it never touches the relationship, employment category (S20),
 * contract (S21), placement (S11), or any supervisory concept.
 *
 * The EmploymentRelationship is re-fetched fresh with lockForUpdate() as the first statement —
 * the S10–S21 discipline — serialising this command against EndEmploymentRelationship and any
 * other recording on the same relationship; the employment_job_title_periods_no_overlap EXCLUDE
 * constraint is the independent database-level backstop.
 */
final class RecordEmploymentJobTitlePeriod
{
    /**
     * @throws EmploymentRelationshipAlreadyEndedException|InvalidEmploymentJobTitleException
     * @throws InvalidEmploymentJobTitlePeriodDateException
     */
    public function handle(
        EmploymentRelationship $relationship,
        JobTitle $jobTitle,
        string $effectiveFrom,
    ): EmploymentJobTitlePeriod {
        $freshRelationship = EmploymentRelationship::query()
            ->where('id', $relationship->getKey())
            ->lockForUpdate()
            ->firstOrFail();

        if ($freshRelationship->end_knowledge_state === 'KNOWN') {
            throw new EmploymentRelationshipAlreadyEndedException;
        }

        $freshJobTitle = JobTitle::query()
            ->where('id', $jobTitle->getKey())
            ->first();

        if ($freshJobTitle === null || ! $freshJobTitle->is_active) {
            throw new InvalidEmploymentJobTitleException;
        }

        $newFrom = Carbon::parse($effectiveFrom);

        // A title MAY start on the relationship's own effective_from (ADR-S22-001 §5) but never
        // before it. effective_from is immutable after CreateEmploymentRelationship, so this
        // application-level comparison carries no concurrency risk (S10 spec §7.3 disclosure).
        if ($newFrom->lt($freshRelationship->effective_from)) {
            throw new InvalidEmploymentJobTitlePeriodDateException;
        }

        // Compared against the LATEST recorded period (open or closed), so a backdated period can
        // never be inserted before, or on top of, later history.
        $latestPeriod = EmploymentJobTitlePeriod::query()
            ->where('employment_relationship_id', $freshRelationship->getKey())
            ->orderByDesc('effective_from')
            ->first();

        if ($latestPeriod !== null && $newFrom->lte($latestPeriod->effective_from)) {
            throw new InvalidEmploymentJobTitlePeriodDateException;
        }

        if ($latestPeriod !== null && $latestPeriod->effective_to === null) {
            try {
                $latestPeriod->update(['effective_to' => $effectiveFrom]);
            } catch (QueryException $e) {
                if (Errors::isCheckViolation($e)) {
                    throw new InvalidEmploymentJobTitlePeriodDateException;
                }

                throw $e;
            }
        }

        $period = new EmploymentJobTitlePeriod([
            'employment_relationship_id' => $freshRelationship->getKey(),
            'job_title_id' => $freshJobTitle->getKey(),
            'effective_from' => $effectiveFrom,
            'effective_to' => null,
            'start_knowledge_state' => 'KNOWN',
        ]);

        try {
            $period->save();
        } catch (QueryException $e) {
            if (Errors::isExclusionViolation($e) || Errors::isCheckViolation($e)) {
                throw new InvalidEmploymentJobTitlePeriodDateException;
            }

            throw $e;
        }

        return $period->refresh();
    }
}
