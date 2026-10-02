<?php

namespace App\Modules\HumanResources\Application\Queries\Reporting;

/**
 * The immutable S44 result for one month (docs/workforce-analytics-foundation-specification.md §S44.7): the canonical Person records, the
 * month's terminal events that are NOT part of the S37 population, and the analytics sections computed from those same facts.
 */
final class WorkforceAnalyticsResult
{
    public const DENOMINATOR_OVERALL_HEADCOUNT = 'OVERALL_HEADCOUNT';

    /** The only meaning of a percentage on a multi-value temporal dimension: it never implies the buckets partition the population. */
    public const SHARE_EXPOSED_SEMANTICS = 'SHARE_OF_OVERALL_POPULATION_EXPOSED_TO_BUCKET';

    /** @var list<string> the data-quality codes S44 exposes — every one is an existing code of an earlier stage, reused only for the same semantic condition */
    public const DATA_QUALITY_CODES = [
        EmploymentStatusReportResult::DQ_INDETERMINATE_STATUS_COVERAGE,
        EmploymentStatusReportResult::DQ_UNKNOWN_LEGACY_RELATIONSHIP_END,
        EmploymentStatusReportResult::DQ_RELATIONSHIP_END_REASON_NOT_RECORDED,
        HumanCadreResult::DQ_PRIMARY_QUALIFICATION_REQUIRED,
        HumanCadreResult::DQ_BIRTH_DATE_AFTER_REPORT_DATE,
        HumanCadreResult::DQ_TRAVEL_PAY_STATUS_NOT_RECORDED,
        AdministrativeReportResult::DQ_GENDER_NOT_RECORDED,
        AdministrativeReportResult::DQ_ORGANIZATIONAL_PLACEMENT_NOT_RECORDED,
        AdministrativeReportResult::DQ_ACTUAL_WORKPLACE_NOT_DETERMINABLE,
    ];

    /** @var list<string> the data-quality codes whose grain is the relationship (the rest are Person-grain) */
    public const RELATIONSHIP_GRAIN_CODES = [
        EmploymentStatusReportResult::DQ_INDETERMINATE_STATUS_COVERAGE,
        EmploymentStatusReportResult::DQ_RELATIONSHIP_END_REASON_NOT_RECORDED,
        AdministrativeReportResult::DQ_ORGANIZATIONAL_PLACEMENT_NOT_RECORDED,
        AdministrativeReportResult::DQ_ACTUAL_WORKPLACE_NOT_DETERMINABLE,
    ];

    /** @var list<string> the status outcomes always listed in the exposure section */
    public const STATUS_OUTCOMES = EmploymentStatusReportResult::EXPOSURE_OUTCOMES;

    /**
     * @param  array<string, mixed>  $sections  population … data_quality, derived from $rows and $terminalEvents
     * @param  list<WorkforceAnalyticsPersonRecord>  $rows  one per Person of the S37 population, in the S37 order
     * @param  list<array<string, mixed>>  $terminalEvents  EVERY KNOWN end dated in the month (relationship_in_monthly_population distinguishes the event-only ones)
     */
    public function __construct(
        public readonly string $monthStart,
        public readonly string $nextMonthStart,
        public readonly string $monthEnd,
        public readonly int $overallHeadcount,
        public readonly array $sections,
        public readonly array $rows,
        public readonly array $terminalEvents,
    ) {}
}
