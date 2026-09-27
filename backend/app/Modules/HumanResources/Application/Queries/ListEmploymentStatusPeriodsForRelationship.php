<?php

namespace App\Modules\HumanResources\Application\Queries;

use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\EmploymentRelationship;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\EmploymentStatusPeriod;
use Illuminate\Database\Eloquent\Collection;

/**
 * Every status period for an Employment Relationship, most recent first (spec §12). No separate
 * "current status" query exists — the first row of this list (equivalently, the one row with
 * effective_to IS NULL, when the relationship is still open) already is the current status.
 */
final class ListEmploymentStatusPeriodsForRelationship
{
    public function __invoke(EmploymentRelationship $relationship): Collection
    {
        return EmploymentStatusPeriod::query()
            ->where('employment_relationship_id', $relationship->getKey())
            ->orderByDesc('effective_from')
            ->get();
    }
}
