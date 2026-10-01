<?php

namespace App\Modules\HumanResources\Application\Queries\Reporting;

/**
 * The immutable R2 result for one month (docs/administrative-report-foundation-specification.md §S42.10): the canonical Person
 * records and the official sections computed from those same records.
 */
final class AdministrativeReportResult
{
    public const LABEL_SEMANTICS = 'CURRENT_RECORDED_LABEL';

    public const DQ_INDETERMINATE_DUTY_STATE = 'INDETERMINATE_DUTY_STATE';

    public const DQ_JOB_TITLE_NOT_RECORDED = 'JOB_TITLE_NOT_RECORDED';

    public const DQ_ADMINISTRATOR_MAPPING_UNMAPPED = 'ADMINISTRATOR_MAPPING_UNMAPPED';

    public const DQ_GENDER_NOT_RECORDED = 'GENDER_NOT_RECORDED';

    public const DQ_ORGANIZATIONAL_PLACEMENT_NOT_RECORDED = 'ORGANIZATIONAL_PLACEMENT_NOT_RECORDED';

    public const DQ_ACTUAL_WORKPLACE_NOT_DETERMINABLE = 'ACTUAL_WORKPLACE_NOT_DETERMINABLE';

    /** @var list<string> every R2 data-quality code, in display order */
    public const DATA_QUALITY_CODES = [
        self::DQ_INDETERMINATE_DUTY_STATE,
        self::DQ_JOB_TITLE_NOT_RECORDED,
        self::DQ_ADMINISTRATOR_MAPPING_UNMAPPED,
        self::DQ_GENDER_NOT_RECORDED,
        self::DQ_ORGANIZATIONAL_PLACEMENT_NOT_RECORDED,
        self::DQ_ACTUAL_WORKPLACE_NOT_DETERMINABLE,
    ];

    /**
     * @param  array<string, int>  $population  has_on_duty, no_on_duty_excluded, indeterminate_excluded (metadata, not business totals)
     * @param  array<string, mixed>  $sections  general_summary … data_quality, all derived from $rows
     * @param  list<AdministrativeReportPersonRecord>  $rows  one per HAS_ON_DUTY Person, ordered by Person id (the S37 order)
     */
    public function __construct(
        public readonly string $monthStart,
        public readonly string $nextMonthStart,
        public readonly string $monthEnd,
        public readonly int $overallHeadcount,
        public readonly array $population,
        public readonly array $sections,
        public readonly array $rows,
    ) {}
}
