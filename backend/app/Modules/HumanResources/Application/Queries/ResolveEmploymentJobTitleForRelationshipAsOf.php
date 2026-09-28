<?php

namespace App\Modules\HumanResources\Application\Queries;

use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\EmploymentJobTitlePeriod;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\EmploymentRelationship;
use Illuminate\Support\Carbon;

/**
 * As-of reader for hr.employment_job_title_periods
 * (docs/employment-job-title-history-foundation-specification.md §S22.14/§S22.18): "which
 * employment job title did this Employment Relationship hold on date X?". Pure read. Mirrors the
 * S06/S20/S21 as-of readers: explicit date (never an implicit today()), half-open
 * [effective_from, effective_to) wins, and null — UNRESOLVED, never a guessed or "current" title —
 * when no period covers that date. Returns the period (job_title_id + start_knowledge_state) so a
 * caller can tell a known start from a legacy snapshot-evidenced one; no is_active filter is
 * applied, so a later deactivation never breaks a historical read.
 */
final class ResolveEmploymentJobTitleForRelationshipAsOf
{
    public function __invoke(EmploymentRelationship $relationship, string|Carbon $date): ?EmploymentJobTitlePeriod
    {
        $date = $date instanceof Carbon ? $date->toDateString() : $date;

        return EmploymentJobTitlePeriod::query()
            ->where('employment_relationship_id', $relationship->getKey())
            ->where('effective_from', '<=', $date)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>', $date))
            ->first();
    }
}
