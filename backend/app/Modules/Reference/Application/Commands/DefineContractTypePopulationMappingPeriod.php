<?php

namespace App\Modules\Reference\Application\Commands;

use App\Modules\Platform\Infrastructure\Persistence\Postgres\PostgresErrorClassifier as Errors;
use App\Modules\Reference\Domain\Exceptions\OverlappingBehaviorPeriodException;
use App\Modules\Reference\Infrastructure\Persistence\Eloquent\ContractBasedPopulationCategory;
use App\Modules\Reference\Infrastructure\Persistence\Eloquent\ContractType;
use App\Modules\Reference\Infrastructure\Persistence\Eloquent\ContractTypePopulationMapping;
use Illuminate\Database\QueryException;

/**
 * Append-only (S06 spec §9/§12.5): always inserts a new period row, never updates or deletes an
 * existing one. The PostgreSQL EXCLUDE constraint on ref.contract_type_population_mappings
 * guarantees no two periods for the same contract type ever overlap.
 */
final class DefineContractTypePopulationMappingPeriod
{
    /** @throws OverlappingBehaviorPeriodException */
    public function handle(
        ContractType $contractType,
        ContractBasedPopulationCategory $populationCategory,
        string $effectiveFrom,
        ?string $effectiveTo,
    ): ContractTypePopulationMapping {
        $period = new ContractTypePopulationMapping([
            'contract_type_id' => $contractType->getKey(),
            'population_category_id' => $populationCategory->getKey(),
            'effective_from' => $effectiveFrom,
            'effective_to' => $effectiveTo,
        ]);

        try {
            $period->save();
        } catch (QueryException $e) {
            if (Errors::isExclusionViolation($e)) {
                throw new OverlappingBehaviorPeriodException;
            }

            throw $e;
        }

        return $period;
    }
}
