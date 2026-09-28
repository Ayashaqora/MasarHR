<?php

namespace App\Modules\HumanResources\Application\Queries;

use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\EmploymentCategoryPeriod;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\EmploymentRelationship;
use App\Modules\Reference\Infrastructure\Persistence\Eloquent\EmploymentCategory;
use Illuminate\Support\Carbon;

/**
 * As-of reader for hr.employment_category_periods
 * (docs/employment-category-history-foundation-specification.md §S20.13/§S20.15): "what
 * Employment Category did this Employment Relationship have as of date X?". Pure read, no
 * mutation. Mirrors the S06 Reference-module as-of readers (e.g. ResolveSpecialtyCadreCategoryAsOf)
 * exactly: the date is always an explicit parameter (never an implicit today()), the half-open
 * [effective_from, effective_to) period covering that date wins, and null is returned — UNRESOLVED,
 * never a guessed or "current" category — when no period covers it. The resolved category is
 * returned regardless of its current is_active flag, so a later deactivation never breaks a
 * historical read.
 */
final class ResolveEmploymentCategoryForRelationshipAsOf
{
    public function __invoke(EmploymentRelationship $relationship, string|Carbon $date): ?EmploymentCategory
    {
        $date = $date instanceof Carbon ? $date->toDateString() : $date;

        $period = EmploymentCategoryPeriod::query()
            ->where('employment_relationship_id', $relationship->getKey())
            ->where('effective_from', '<=', $date)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>', $date))
            ->first();

        if ($period === null) {
            return null;
        }

        return EmploymentCategory::query()->find($period->employment_category_id);
    }
}
