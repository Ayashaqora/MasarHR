<?php

namespace App\Modules\Reference\Application\Commands;

use App\Modules\Platform\Infrastructure\Persistence\Postgres\PostgresErrorClassifier as Errors;
use App\Modules\Reference\Domain\Exceptions\OverlappingBehaviorPeriodException;
use App\Modules\Reference\Infrastructure\Persistence\Eloquent\MonthlyCadreCategory;
use App\Modules\Reference\Infrastructure\Persistence\Eloquent\Specialty;
use App\Modules\Reference\Infrastructure\Persistence\Eloquent\SpecialtyCadreCategoryMapping;
use Illuminate\Database\QueryException;

/**
 * Append-only (S06 spec §9/§12.2): always inserts a new period row, never updates or deletes an
 * existing one. The PostgreSQL EXCLUDE constraint on ref.specialty_cadre_category_mappings is what
 * actually guarantees no two periods for the same specialty ever overlap; a violation (SQLSTATE
 * 23P01) is translated into OverlappingBehaviorPeriodException here, reused as-is from S05 (spec
 * §15). No is_active check is performed on either the specialty or the target category, matching
 * DefineEmploymentStatusDetailBehaviorPeriod's existing precedent exactly (adversarial review §28
 * item 10).
 */
final class DefineSpecialtyCadreCategoryMappingPeriod
{
    /** @throws OverlappingBehaviorPeriodException */
    public function handle(
        Specialty $specialty,
        MonthlyCadreCategory $cadreCategory,
        string $effectiveFrom,
        ?string $effectiveTo,
    ): SpecialtyCadreCategoryMapping {
        $period = new SpecialtyCadreCategoryMapping([
            'specialty_id' => $specialty->getKey(),
            'cadre_category_id' => $cadreCategory->getKey(),
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
