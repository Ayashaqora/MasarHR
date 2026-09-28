<?php

namespace App\Modules\HumanResources\Application\Queries;

use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\EmploymentRelationship;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\OrganizationalPlacementPeriod;
use Illuminate\Support\Carbon;

/**
 * As-of reader for hr.organizational_placement_periods — the ORGANIZATIONAL / ORIGINAL placement
 * stream of S11 (transfers are already reflected in it by S14) —
 * (docs/reporting-as-of-foundation-specification.md §S27.5). Never the actual workplace: temporary
 * movements are resolved separately by ResolveActualWorkplaceForRelationshipAsOf. Half-open rule;
 * null — UNRESOLVED — when no placement covers the date (no history is fabricated).
 */
final class ResolveOrganizationalPlacementForRelationshipAsOf
{
    public function __invoke(EmploymentRelationship $relationship, string|Carbon $date): ?OrganizationalPlacementPeriod
    {
        $date = $date instanceof Carbon ? $date->toDateString() : $date;

        return OrganizationalPlacementPeriod::query()
            ->where('employment_relationship_id', $relationship->getKey())
            ->where('effective_from', '<=', $date)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>', $date))
            ->first();
    }
}
