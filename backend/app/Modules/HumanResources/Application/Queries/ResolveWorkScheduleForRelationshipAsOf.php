<?php

namespace App\Modules\HumanResources\Application\Queries;

use App\Modules\HumanResources\Domain\WorkScheduleAsOf;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\EmploymentRelationship;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\WorkSchedulePeriod;
use Illuminate\Support\Carbon;

/**
 * As-of reader for hr.work_schedule_periods (docs/work-schedule-foundation-specification.md §S29.10):
 * "which weekdays is this Employment Relationship scheduled to work on business date D?". Pure read,
 * explicit date (never today()), half-open [effective_from, effective_to) — never the latest
 * recorded row. NOT_RECORDED when no period covers D: never a default week. Built to be reused by a
 * future Partial Secondment / weekday-aware actual-workplace consumer; S29 wires no such consumer.
 */
final class ResolveWorkScheduleForRelationshipAsOf
{
    public function __invoke(EmploymentRelationship $relationship, string|Carbon $date): WorkScheduleAsOf
    {
        $date = $date instanceof Carbon ? $date->toDateString() : $date;

        $period = WorkSchedulePeriod::query()
            ->with('weekdays')
            ->where('employment_relationship_id', $relationship->getKey())
            ->where('effective_from', '<=', $date)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>', $date))
            ->first();

        return $period === null ? WorkScheduleAsOf::notRecorded() : WorkScheduleAsOf::resolved($period);
    }
}
