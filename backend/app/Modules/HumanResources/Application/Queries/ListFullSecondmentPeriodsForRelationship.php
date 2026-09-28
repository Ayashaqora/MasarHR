<?php

namespace App\Modules\HumanResources\Application\Queries;

use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\EmploymentRelationship;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\FullSecondmentPeriod;
use Illuminate\Database\Eloquent\Collection;

/**
 * Every full secondment period for an Employment Relationship, most recent first (spec §14).
 * Mirrors ListOrganizationalPlacementPeriodsForRelationship (S11) exactly.
 */
final class ListFullSecondmentPeriodsForRelationship
{
    public function __invoke(EmploymentRelationship $relationship): Collection
    {
        return FullSecondmentPeriod::query()
            ->where('employment_relationship_id', $relationship->getKey())
            ->orderByDesc('effective_from')
            ->get();
    }
}
