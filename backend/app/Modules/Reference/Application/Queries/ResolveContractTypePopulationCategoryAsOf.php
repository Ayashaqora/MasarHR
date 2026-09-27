<?php

namespace App\Modules\Reference\Application\Queries;

use App\Modules\Reference\Infrastructure\Persistence\Eloquent\ContractBasedPopulationCategory;
use App\Modules\Reference\Infrastructure\Persistence\Eloquent\ContractType;
use App\Modules\Reference\Infrastructure\Persistence\Eloquent\ContractTypePopulationMapping;
use Illuminate\Support\Carbon;

/**
 * As-of reader for ref.contract_type_population_mappings (S06 spec §12.5/§14). Pure read, no
 * mutation. Returns null when no mapping period covers the given date — UNRESOLVED.
 */
final class ResolveContractTypePopulationCategoryAsOf
{
    public function __invoke(ContractType $contractType, string|Carbon $date): ?ContractBasedPopulationCategory
    {
        $date = $date instanceof Carbon ? $date->toDateString() : $date;

        $mapping = ContractTypePopulationMapping::query()
            ->where('contract_type_id', $contractType->getKey())
            ->where('effective_from', '<=', $date)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>', $date))
            ->first();

        if ($mapping === null) {
            return null;
        }

        return ContractBasedPopulationCategory::query()->find($mapping->population_category_id);
    }
}
