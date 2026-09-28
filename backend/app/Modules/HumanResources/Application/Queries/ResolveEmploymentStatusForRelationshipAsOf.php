<?php

namespace App\Modules\HumanResources\Application\Queries;

use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\EmploymentRelationship;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\EmploymentStatusPeriod;
use Illuminate\Support\Carbon;

/**
 * As-of reader for hr.employment_status_periods (docs/reporting-as-of-foundation-specification.md
 * §S27.4, ADR-S27-001): "which employment status period was in force for this Employment
 * Relationship on business date X?". Pure read with the S20–S26 half-open rule
 * (effective_from <= X AND (effective_to IS NULL OR X < effective_to)); null — UNRESOLVED — when no
 * period covers X. The status detail's behavior flags on the SAME date come from the existing S06
 * ResolveEmploymentStatusDetailBehaviorAsOf; no new status semantics are introduced.
 */
final class ResolveEmploymentStatusForRelationshipAsOf
{
    public function __invoke(EmploymentRelationship $relationship, string|Carbon $date): ?EmploymentStatusPeriod
    {
        $date = $date instanceof Carbon ? $date->toDateString() : $date;

        return EmploymentStatusPeriod::query()
            ->where('employment_relationship_id', $relationship->getKey())
            ->where('effective_from', '<=', $date)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>', $date))
            ->first();
    }
}
