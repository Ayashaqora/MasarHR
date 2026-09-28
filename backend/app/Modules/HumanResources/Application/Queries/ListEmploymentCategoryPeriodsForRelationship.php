<?php

namespace App\Modules\HumanResources\Application\Queries;

use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\EmploymentCategoryPeriod;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\EmploymentRelationship;
use Illuminate\Database\Eloquent\Collection;

/**
 * Every employment category period for one Employment Relationship, most recent first
 * (docs/employment-category-history-foundation-specification.md §S20.13) — mirrors
 * ListEmploymentStatusPeriodsForRelationship (S10) exactly. Scoped to exactly this relationship:
 * a reappointment's new relationship never sees an earlier relationship's periods. Periods whose
 * ref.employment_categories row has since been deactivated are returned unchanged — no is_active
 * filter is ever applied to history.
 */
final class ListEmploymentCategoryPeriodsForRelationship
{
    public function __invoke(EmploymentRelationship $relationship): Collection
    {
        return EmploymentCategoryPeriod::query()
            ->where('employment_relationship_id', $relationship->getKey())
            ->orderByDesc('effective_from')
            ->get();
    }
}
