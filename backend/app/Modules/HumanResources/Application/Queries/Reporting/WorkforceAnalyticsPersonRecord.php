<?php

namespace App\Modules\HumanResources\Application\Queries\Reporting;

/**
 * The ONE canonical S44 record of a Person of the S37 monthly population (docs/workforce-analytics-foundation-specification.md §S44.7). Every
 * analytics section is derived from the collection of these records plus the month's terminal events. Immutable, never persisted. The
 * Person-grain facts (gender, age, service, Primary Qualification) are single-valued; the relationships keep their own temporal segments
 * (status, category, contract, specialty, organizational placement, actual workplace) because a Person may be exposed to several values of
 * those dimensions in one month.
 */
final class WorkforceAnalyticsPersonRecord
{
    /**
     * @param  array{state: string, code: string|null, name_ar: string|null, name_en: string|null}  $gender  RECORDED | NOT_RECORDED (CURRENT_RECORDED)
     * @param  array{state: string, years: int|null, band: string}  $age  CALCULABLE | NOT_RECORDED | NOT_CALCULABLE at the month's last day
     * @param  array<string, mixed>  $service  CALCULABLE | INCOMPLETE cumulative service (every relationship of the Person)
     * @param  array<string, mixed>  $primaryQualification  PRIMARY | NOT_RECORDED (the current Primary Qualification only)
     * @param  list<array<string, mixed>>  $relationships  every relationship overlapping the month, each with its own segments
     * @param  list<string>  $dataQuality  the S44 data-quality codes that affect this Person
     */
    public function __construct(
        public readonly string $personId,
        public readonly string $dutyClassification,
        public readonly array $gender,
        public readonly array $age,
        public readonly array $service,
        public readonly array $primaryQualification,
        public readonly array $relationships,
        public readonly array $dataQuality,
    ) {}
}
