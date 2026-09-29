<?php

namespace App\Modules\HumanResources\Application\Queries;

use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\EmploymentRelationship;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\PartialSecondmentPeriod;
use Illuminate\Database\Eloquent\Collection;

/**
 * Every Partial Secondment period for one Employment Relationship, most recent start first, with
 * weekdays eager-loaded (docs/partial-secondment-foundation-specification.md §S30.19). Scoped to
 * exactly this relationship: a reappointment's new relationship never sees an earlier one's.
 */
final class ListPartialSecondmentPeriodsForRelationship
{
    public function __invoke(EmploymentRelationship $relationship): Collection
    {
        return PartialSecondmentPeriod::query()
            ->with('weekdays')
            ->where('employment_relationship_id', $relationship->getKey())
            ->orderByDesc('effective_from')
            ->orderBy('id')
            ->get();
    }
}
