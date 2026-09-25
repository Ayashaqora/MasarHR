<?php

namespace App\Modules\Reference\Application\Commands;

use App\Modules\Platform\Infrastructure\Persistence\Postgres\PostgresErrorClassifier as Errors;
use App\Modules\Reference\Domain\Exceptions\OverlappingBehaviorPeriodException;
use App\Modules\Reference\Infrastructure\Persistence\Eloquent\EmploymentStatusDetail;
use App\Modules\Reference\Infrastructure\Persistence\Eloquent\EmploymentStatusDetailBehavior;
use Illuminate\Database\QueryException;

/**
 * Append-only (S05 §12/D4): always inserts a new period row, never updates or deletes an
 * existing one. The PostgreSQL EXCLUDE constraint on ref.employment_status_detail_behaviors is
 * what actually guarantees no two periods for the same status detail ever overlap; a violation
 * (SQLSTATE 23P01) is translated into OverlappingBehaviorPeriodException here.
 */
final class DefineEmploymentStatusDetailBehaviorPeriod
{
    /** @throws OverlappingBehaviorPeriodException */
    public function handle(
        EmploymentStatusDetail $detail,
        string $effectiveFrom,
        ?string $effectiveTo,
        bool $participatesInActiveWorkforce,
        bool $isOngoingRelationship,
        bool $isRelationshipEnding,
        bool $isTerminal,
        ?bool $allowsReappointment,
        ?bool $countsInMonthlyReporting,
    ): EmploymentStatusDetailBehavior {
        $period = new EmploymentStatusDetailBehavior([
            'status_detail_id' => $detail->getKey(),
            'effective_from' => $effectiveFrom,
            'effective_to' => $effectiveTo,
            'participates_in_active_workforce' => $participatesInActiveWorkforce,
            'is_ongoing_relationship' => $isOngoingRelationship,
            'is_relationship_ending' => $isRelationshipEnding,
            'is_terminal' => $isTerminal,
            'allows_reappointment' => $allowsReappointment,
            'counts_in_monthly_reporting' => $countsInMonthlyReporting,
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
