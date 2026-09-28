<?php

namespace App\Modules\HumanResources\Application\Queries;

use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\EmploymentJobTitlePeriod;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\EmploymentRelationship;
use Illuminate\Database\Eloquent\Collection;

/**
 * Every employment job title period for one Employment Relationship, most recent first
 * (docs/employment-job-title-history-foundation-specification.md §S22.14) — mirrors
 * ListEmploymentCategoryPeriodsForRelationship (S20). Scoped to exactly this relationship: a
 * reappointment's new relationship never sees an earlier relationship's titles. Periods whose
 * ref.job_titles row has since been deactivated are returned unchanged.
 */
final class ListEmploymentJobTitlePeriodsForRelationship
{
    public function __invoke(EmploymentRelationship $relationship): Collection
    {
        return EmploymentJobTitlePeriod::query()
            ->where('employment_relationship_id', $relationship->getKey())
            ->orderByDesc('effective_from')
            ->get();
    }
}
