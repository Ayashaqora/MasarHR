<?php

namespace App\Modules\HumanResources\Application\Queries\Reporting;

/**
 * The S39 REPORT-3 (Monthly Not-On-Duty) result for ONE explicit month
 * (docs/monthly-not-on-duty-report-foundation-specification.md §S39.14). An immutable, never-persisted projection over the
 * S37 canonical dataset: the REPORT-3 Person rows (S37 dutyClassification NO_ON_DUTY only), the authoritative total, the
 * INDETERMINATE data-quality count and the batched display enrichment. It carries no report logic of its own: reasons and
 * workplace segments are the S37 relationship-level structures, unchanged.
 */
final class MonthlyNotOnDutyResult
{
    /**
     * @param  int  $totalNotOnDutyPersons  the complete count of unique REPORT-3 Person rows (never a page size)
     * @param  int  $indeterminateCount  report-level data-quality metadata: Persons S37 classified INDETERMINATE (excluded from rows and total)
     * @param  list<array{person: array{person_id: string, national_id: string, full_name: ?string, gender_id: ?string, birth_date: ?string, qualifications: list<array<string, string|null>>, qualification_semantics: string}, duty_classification: string, relationships: list<MonthlyRelationshipSegment>}>  $rows  unique Persons in S37 order
     * @param  array<string, array{code: string, name_ar: string, name_en: ?string}>  $statusLabels  existing ref.employment_status_details labels by status_detail_id (one batched lookup)
     */
    public function __construct(
        public readonly string $monthStart,
        public readonly string $nextMonthStart,
        public readonly int $totalNotOnDutyPersons,
        public readonly int $indeterminateCount,
        public readonly array $rows,
        public readonly array $statusLabels,
    ) {}
}
