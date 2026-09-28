<?php

namespace App\Modules\HumanResources\Application\Queries;

use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\EmploymentContractPeriod;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\EmploymentRelationship;
use Illuminate\Database\Eloquent\Collection;

/**
 * Every employment contract period for one Employment Relationship, most recent first
 * (docs/employment-contract-foundation-specification.md §S21.14) — mirrors
 * ListEmploymentCategoryPeriodsForRelationship (S20). Scoped to exactly this relationship: a
 * reappointment's new relationship never sees an earlier relationship's contracts. Periods whose
 * ref.contract_types row has since been deactivated are returned unchanged.
 */
final class ListEmploymentContractPeriodsForRelationship
{
    public function __invoke(EmploymentRelationship $relationship): Collection
    {
        return EmploymentContractPeriod::query()
            ->where('employment_relationship_id', $relationship->getKey())
            ->orderByDesc('effective_from')
            ->get();
    }
}
