<?php

namespace App\Modules\HumanResources\Application\Queries;

use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\EmploymentRelationship;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\WorkSchedulePeriod;
use Illuminate\Database\Eloquent\Collection;

/**
 * Every work schedule period for one Employment Relationship, most recent first, with weekdays
 * eager-loaded (docs/work-schedule-foundation-specification.md §S29.14). Scoped to exactly this
 * relationship: a reappointment's new relationship never sees an earlier relationship's schedule.
 */
final class ListWorkSchedulePeriodsForRelationship
{
    public function __invoke(EmploymentRelationship $relationship): Collection
    {
        return WorkSchedulePeriod::query()
            ->with('weekdays')
            ->where('employment_relationship_id', $relationship->getKey())
            ->orderByDesc('effective_from')
            ->get();
    }
}
