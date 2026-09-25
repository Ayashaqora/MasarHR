<?php

namespace App\Modules\Reference\Application\Queries;

use App\Modules\Reference\Infrastructure\Persistence\Eloquent\MonthlyCadreCategory;
use App\Modules\Reference\Infrastructure\Persistence\Eloquent\Specialty;
use App\Modules\Reference\Infrastructure\Persistence\Eloquent\SpecialtyCadreCategoryMapping;
use Illuminate\Support\Carbon;

/**
 * As-of reader for ref.specialty_cadre_category_mappings (S06 spec §12.2/§14). Pure read, no
 * mutation. Returns null when no mapping period covers the given date — UNRESOLVED, never a
 * guessed "other" category (Report 1's own explicit "never silently map unknown values to other").
 */
final class ResolveSpecialtyCadreCategoryAsOf
{
    public function __invoke(Specialty $specialty, string|Carbon $date): ?MonthlyCadreCategory
    {
        $date = $date instanceof Carbon ? $date->toDateString() : $date;

        $mapping = SpecialtyCadreCategoryMapping::query()
            ->where('specialty_id', $specialty->getKey())
            ->where('effective_from', '<=', $date)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>', $date))
            ->first();

        if ($mapping === null) {
            return null;
        }

        return MonthlyCadreCategory::query()->find($mapping->cadre_category_id);
    }
}
