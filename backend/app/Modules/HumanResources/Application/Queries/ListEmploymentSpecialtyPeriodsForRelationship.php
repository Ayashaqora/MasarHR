<?php

namespace App\Modules\HumanResources\Application\Queries;

use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\EmploymentRelationship;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\EmploymentSpecialtyPeriod;
use Illuminate\Database\Eloquent\Collection;

/**
 * Every employee specialty period for one Employment Relationship, most recent first
 * (docs/employee-specialty-history-foundation-specification.md §S26.14) — mirrors
 * ListEmploymentJobTitlePeriodsForRelationship (S22). Scoped to exactly this relationship: a
 * reappointment's new relationship never sees an earlier relationship's specialties
 * (ADR-S26-001 G). Periods whose ref.specialties row has since been deactivated are returned
 * unchanged.
 */
final class ListEmploymentSpecialtyPeriodsForRelationship
{
    public function __invoke(EmploymentRelationship $relationship): Collection
    {
        return EmploymentSpecialtyPeriod::query()
            ->where('employment_relationship_id', $relationship->getKey())
            ->orderByDesc('effective_from')
            ->get();
    }
}
