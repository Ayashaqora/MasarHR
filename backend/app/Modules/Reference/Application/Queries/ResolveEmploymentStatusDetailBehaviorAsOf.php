<?php

namespace App\Modules\Reference\Application\Queries;

use App\Modules\Reference\Infrastructure\Persistence\Eloquent\EmploymentStatusDetail;
use App\Modules\Reference\Infrastructure\Persistence\Eloquent\EmploymentStatusDetailBehavior;
use Illuminate\Support\Carbon;

/**
 * As-of reader for ref.employment_status_detail_behaviors (S06 spec §11/§14, closing the gap S05
 * shipped the write side for but never built a reader for). Pure read, no mutation. Returns null
 * when no period covers the given date — UNRESOLVED, never a guessed default (matches the
 * ResolveMaritalStatusByArabicSourceValue UNRESOLVED contract established in CORRECTIVE-01).
 */
final class ResolveEmploymentStatusDetailBehaviorAsOf
{
    public function __invoke(EmploymentStatusDetail $detail, string|Carbon $date): ?EmploymentStatusDetailBehavior
    {
        $date = $date instanceof Carbon ? $date->toDateString() : $date;

        return EmploymentStatusDetailBehavior::query()
            ->where('status_detail_id', $detail->getKey())
            ->where('effective_from', '<=', $date)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>', $date))
            ->first();
    }
}
