<?php

namespace App\Modules\HumanResources\Application\Queries;

use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\EmploymentRelationship;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\ReturnIntentionPeriod;
use Illuminate\Support\Carbon;

/**
 * S34: the Return Intention period in force on a business date (half-open
 * effective_from <= X AND (effective_to IS NULL OR X < effective_to)); null means NOT RECORDED.
 * Never derived from employment status and never defaulted.
 */
final class ResolveReturnIntentionAsOf
{
    public function __invoke(EmploymentRelationship $relationship, string|Carbon $date): ?ReturnIntentionPeriod
    {
        $date = $date instanceof Carbon ? $date->toDateString() : $date;

        return ReturnIntentionPeriod::query()
            ->where('employment_relationship_id', $relationship->getKey())
            ->where('effective_from', '<=', $date)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>', $date))
            ->first();
    }
}
