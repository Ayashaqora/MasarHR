<?php

namespace App\Modules\HumanResources\Application\Queries;

use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\EmploymentRelationship;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\ReturnIntentionPeriod;
use Illuminate\Database\Eloquent\Collection;

/** S34: every persisted Return Intention period of a relationship, most recent first. */
final class ListReturnIntentionPeriodsForRelationship
{
    public function __invoke(EmploymentRelationship $relationship): Collection
    {
        return ReturnIntentionPeriod::query()
            ->where('employment_relationship_id', $relationship->getKey())
            ->orderByDesc('effective_from')
            ->get();
    }
}
