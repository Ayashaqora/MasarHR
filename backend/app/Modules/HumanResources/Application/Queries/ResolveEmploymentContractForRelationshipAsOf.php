<?php

namespace App\Modules\HumanResources\Application\Queries;

use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\EmploymentContractPeriod;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\EmploymentRelationship;
use Illuminate\Support\Carbon;

/**
 * As-of reader for hr.employment_contract_periods
 * (docs/employment-contract-foundation-specification.md §S21.14/§S21.17): "which contract (type,
 * start, agreed term) was in force for this Employment Relationship on date X?". Pure read.
 * Mirrors the S06/S20 as-of readers: explicit date (never an implicit today()), half-open ACTUAL
 * validity [effective_from, effective_to) wins, and null — UNRESOLVED, never a guessed contract —
 * when no period is in force on that date (no contract recorded yet, a PERMANENT relationship, a
 * lapse between an expired term and a later renewal, or after the relationship ended). The
 * returned period carries its contract_type_id regardless of that type's current is_active flag.
 */
final class ResolveEmploymentContractForRelationshipAsOf
{
    public function __invoke(EmploymentRelationship $relationship, string|Carbon $date): ?EmploymentContractPeriod
    {
        $date = $date instanceof Carbon ? $date->toDateString() : $date;

        return EmploymentContractPeriod::query()
            ->where('employment_relationship_id', $relationship->getKey())
            ->where('effective_from', '<=', $date)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>', $date))
            ->first();
    }
}
