<?php

namespace App\Modules\HumanResources\Application\Queries;

use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\EmploymentRelationship;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\EmploymentSpecialtyPeriod;
use Illuminate\Support\Carbon;

/**
 * As-of reader for hr.employment_specialty_periods
 * (docs/employee-specialty-history-foundation-specification.md §S26.14/§S26.16, ADR-S26-001 H):
 * "which specialty did this Employment Relationship hold on date X?". Pure read. Mirrors the
 * S06/S20/S21/S22 as-of readers: explicit date (never an implicit today()), half-open
 * [effective_from, effective_to) wins, and null — UNRESOLVED / UNASSIGNED, never a guessed,
 * "current" or "Other" specialty — when no period covers that date. No is_active filter is
 * applied, so a later deactivation never breaks a historical read. The cadre category is NOT
 * resolved here: a reporting caller chains the returned specialty_id into the existing S06
 * ResolveSpecialtyCadreCategoryAsOf at the same date.
 */
final class ResolveEmploymentSpecialtyForRelationshipAsOf
{
    public function __invoke(EmploymentRelationship $relationship, string|Carbon $date): ?EmploymentSpecialtyPeriod
    {
        $date = $date instanceof Carbon ? $date->toDateString() : $date;

        return EmploymentSpecialtyPeriod::query()
            ->where('employment_relationship_id', $relationship->getKey())
            ->where('effective_from', '<=', $date)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>', $date))
            ->first();
    }
}
