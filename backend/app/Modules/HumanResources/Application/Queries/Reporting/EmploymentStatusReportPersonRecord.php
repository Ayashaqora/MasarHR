<?php

namespace App\Modules\HumanResources\Application\Queries\Reporting;

/**
 * The ONE canonical R4 record of a Person for a month (docs/employment-status-report-foundation-specification.md §S43.9). Every
 * official section is derived from the collection of these records. Immutable, never persisted. The Person is one record; each
 * qualifying Employment Relationship keeps its own timeline (never merged, never "latest wins"). The status exposure of a Person is a
 * multi-value temporal fact: the same Person may be exposed to several statuses in one month.
 */
final class EmploymentStatusReportPersonRecord
{
    /**
     * @param  string  $dutyClassification  the S37 Person classification, carried as information only (R4 never filters by it)
     * @param  list<array<string, mixed>>  $relationships  one per qualifying relationship: window, status_segments, return_intention_segments, terminal_event, data_quality
     * @param  list<string>  $dataQuality  the R4 data-quality codes that affect this Person
     */
    public function __construct(
        public readonly string $personId,
        public readonly ?string $nationalId,
        public readonly ?string $fullNameAr,
        public readonly string $dutyClassification,
        public readonly array $relationships,
        public readonly array $dataQuality,
    ) {}
}
