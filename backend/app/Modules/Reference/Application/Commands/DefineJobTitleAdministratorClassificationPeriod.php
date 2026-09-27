<?php

namespace App\Modules\Reference\Application\Commands;

use App\Modules\Platform\Infrastructure\Persistence\Postgres\PostgresErrorClassifier as Errors;
use App\Modules\Reference\Domain\Exceptions\OverlappingBehaviorPeriodException;
use App\Modules\Reference\Infrastructure\Persistence\Eloquent\JobTitle;
use App\Modules\Reference\Infrastructure\Persistence\Eloquent\JobTitleAdministratorClassification;
use Illuminate\Database\QueryException;

/**
 * Append-only (S06 spec §9/§12.3): always inserts a new period row, never updates or deletes an
 * existing one. The PostgreSQL EXCLUDE constraint on ref.job_title_administrator_classifications
 * guarantees no two periods for the same job title ever overlap.
 */
final class DefineJobTitleAdministratorClassificationPeriod
{
    /** @throws OverlappingBehaviorPeriodException */
    public function handle(
        JobTitle $jobTitle,
        bool $isAdministrator,
        string $effectiveFrom,
        ?string $effectiveTo,
    ): JobTitleAdministratorClassification {
        $period = new JobTitleAdministratorClassification([
            'job_title_id' => $jobTitle->getKey(),
            'is_administrator' => $isAdministrator,
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
