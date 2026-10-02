<?php

namespace App\Modules\HumanResources\Application\Queries\Reporting;

/**
 * The immutable R4 result for one month (docs/employment-status-report-foundation-specification.md §S43.9): the canonical Person
 * records and the official sections computed from those same records.
 */
final class EmploymentStatusReportResult
{
    public const DQ_INDETERMINATE_STATUS_COVERAGE = 'INDETERMINATE_STATUS_COVERAGE';

    public const DQ_UNKNOWN_LEGACY_RELATIONSHIP_END = 'UNKNOWN_LEGACY_RELATIONSHIP_END';

    public const DQ_RELATIONSHIP_END_REASON_NOT_RECORDED = 'RELATIONSHIP_END_REASON_NOT_RECORDED';

    /** @var list<string> every R4 data-quality code, in display order (RETURN_INTENTION_NOT_RECORDED is deliberately NOT one: no applicability rule exists) */
    public const DATA_QUALITY_CODES = [
        self::DQ_INDETERMINATE_STATUS_COVERAGE,
        self::DQ_UNKNOWN_LEGACY_RELATIONSHIP_END,
        self::DQ_RELATIONSHIP_END_REASON_NOT_RECORDED,
    ];

    public const OUTCOME_ON_DUTY = 'ON_DUTY';

    public const OUTCOME_INDETERMINATE = 'INDETERMINATE';

    public const REASON_NOT_RECORDED = 'NOT_RECORDED';

    /** @var list<string> the status outcomes always present in the exposure section (zero when nobody is exposed) */
    public const EXPOSURE_OUTCOMES = ['ON_DUTY', 'TRAVELING', 'CAPTIVE', 'SUSPENDED', 'UNPAID_LEAVE', 'EXTERNAL_SICK_LEAVE', 'INDETERMINATE'];

    /** @var list<string> the Return Intention outcomes (the two stored values plus the reporting state NOT_RECORDED) */
    public const RETURN_INTENTION_OUTCOMES = ['WANTS_TO_RETURN', 'DOES_NOT_WANT_TO_RETURN', 'NOT_RECORDED'];

    /**
     * @param  array<string, mixed>  $sections  general_summary … data_quality, all derived from $rows
     * @param  list<EmploymentStatusReportPersonRecord>  $rows  one per Person of the S37 population, ordered by Person id (the S37 order)
     * @param  list<array<string, mixed>>  $terminalEventsOnly  ES-D56 terminal events whose relationship is not part of the S37 population (an end dated exactly month_start); never a Person record
     */
    public function __construct(
        public readonly string $monthStart,
        public readonly string $nextMonthStart,
        public readonly string $monthEnd,
        public readonly int $overallPersons,
        public readonly array $sections,
        public readonly array $rows,
        public readonly array $terminalEventsOnly = [],
    ) {}
}
