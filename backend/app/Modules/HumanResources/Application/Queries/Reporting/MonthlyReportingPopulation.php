<?php

namespace App\Modules\HumanResources\Application\Queries\Reporting;

/**
 * The S37 monthly workforce reporting result for ONE explicit month
 * (docs/monthly-workforce-reporting-semantics-foundation-specification.md §S37.4/§S37.10). An immutable,
 * never-persisted, internal read-model value: one canonical row per PERSON whose Employment Relationships
 * overlap [monthStart, nextMonthStart). It exposes resolved facts and segments only — never a report, a
 * total, a percentage, an age or a monthly value for a dimension that can change inside the month.
 */
final class MonthlyReportingPopulation
{
    /**
     * The explicit, machine-readable limitation every consumer receives with the result: qualifications are the
     * Person's CURRENT recorded facts (S36) — NOT qualifications "as of" the reporting month.
     */
    public const QUALIFICATION_SEMANTICS = 'CURRENT_RECORDED_PERSON_FACTS';

    /** @param list<MonthlyReportingPersonRow> $persons ordered by person id */
    public function __construct(
        public readonly string $monthStart,
        public readonly string $nextMonthStart,
        public readonly string $qualificationSemantics,
        public readonly array $persons,
    ) {}
}
