<?php

namespace App\Modules\HumanResources\Application\Queries;

use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\EmploymentRelationship;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\WorkplaceAssignmentPeriod;
use Illuminate\Database\Eloquent\Collection;

/**
 * Every workplace assignment period for an Employment Relationship, most recent first
 * (docs/workplace-assignment-foundation-specification.md §S16.16). Mirrors
 * ListFullSecondmentPeriodsForRelationship (S12) exactly.
 */
final class ListWorkplaceAssignmentPeriodsForRelationship
{
    public function __invoke(EmploymentRelationship $relationship): Collection
    {
        return WorkplaceAssignmentPeriod::query()
            ->where('employment_relationship_id', $relationship->getKey())
            ->orderByDesc('effective_from')
            ->get();
    }
}
