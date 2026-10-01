<?php

namespace App\Modules\HumanResources\Application\Queries\Reporting;

/**
 * The ONE canonical R2 record of a HAS_ON_DUTY Person for a month (docs/administrative-report-foundation-specification.md §S42.10).
 * Every official section is derived from the collection of these records. Immutable, never persisted. The Person is one record; the
 * temporal dimensions (job title, organizational placement, actual workplace) are preserved as segments per relationship and may
 * place the same Person in several buckets — only the overall headcount is a distinct-Person count of these records.
 */
final class AdministrativeReportPersonRecord
{
    /**
     * @param  array{state: string, code: string|null, id: string|null, name_ar: string|null, name_en: string|null}  $gender  RECORDED (MALE | FEMALE) | NOT_RECORDED — CURRENT_RECORDED
     * @param  list<array<string, mixed>>  $relationships  one per employment relationship of the month: window, job_title_segments, organizational_placement_segments, actual_workplace_segments
     * @param  list<string>  $dataQuality  the R2 data-quality codes that affect this Person
     */
    public function __construct(
        public readonly string $personId,
        public readonly ?string $nationalId,
        public readonly ?string $fullNameAr,
        public readonly array $gender,
        public readonly array $relationships,
        public readonly array $dataQuality,
    ) {}
}
