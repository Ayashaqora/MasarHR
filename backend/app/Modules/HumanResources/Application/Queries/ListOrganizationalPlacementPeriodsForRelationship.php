<?php

namespace App\Modules\HumanResources\Application\Queries;

use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\EmploymentRelationship;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\OrganizationalPlacementPeriod;
use Illuminate\Database\Eloquent\Collection;

/**
 * Every organizational placement period for an Employment Relationship, most recent first (spec
 * §12). No separate "current placement" query exists — the first row of this list (equivalently,
 * the one row with effective_to IS NULL, when one exists) already is the current placement.
 */
final class ListOrganizationalPlacementPeriodsForRelationship
{
    public function __invoke(EmploymentRelationship $relationship): Collection
    {
        return OrganizationalPlacementPeriod::query()
            ->where('employment_relationship_id', $relationship->getKey())
            ->orderByDesc('effective_from')
            ->get();
    }
}
