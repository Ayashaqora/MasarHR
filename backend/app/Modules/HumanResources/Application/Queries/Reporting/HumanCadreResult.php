<?php

namespace App\Modules\HumanResources\Application\Queries\Reporting;

/**
 * The immutable REPORT-1 Human Cadre result for one month (docs/human-cadre-report-foundation-specification.md §S41.8): the
 * canonical Person records, and the official summaries computed from those same records.
 */
final class HumanCadreResult
{
    public const LABEL_SEMANTICS = 'CURRENT_RECORDED_LABEL';

    public const DQ_PRIMARY_QUALIFICATION_REQUIRED = 'PRIMARY_QUALIFICATION_REQUIRED';

    public const DQ_UNKNOWN_LEGACY_RELATIONSHIP_END = 'UNKNOWN_LEGACY_RELATIONSHIP_END';

    public const DQ_TRAVEL_PAY_STATUS_NOT_RECORDED = 'TRAVEL_PAY_STATUS_NOT_RECORDED';

    public const DQ_BIRTH_DATE_AFTER_REPORT_DATE = 'BIRTH_DATE_AFTER_REPORT_DATE';

    public const UNCLASSIFIED = 'UNCLASSIFIED';

    public const NOT_RECORDED = 'NOT_RECORDED';

    /**
     * @param  list<HumanCadrePersonRecord>  $rows  one per included Person, ordered by Person id (the S37 order)
     * @param  array<string, mixed>  $summaries  cadre, specialty, employment_type, gender, qualification, age, service
     */
    public function __construct(
        public readonly string $monthStart,
        public readonly string $nextMonthStart,
        public readonly string $monthEnd,
        public readonly int $overallHeadcount,
        public readonly array $summaries,
        public readonly array $rows,
    ) {}
}
