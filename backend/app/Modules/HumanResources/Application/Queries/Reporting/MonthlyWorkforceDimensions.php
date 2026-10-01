<?php

namespace App\Modules\HumanResources\Application\Queries\Reporting;

/**
 * The immutable, never-persisted S40 result: the S37 Person population (same Persons, same order) enriched with
 * relationship-local temporal dimension segments. Not a report: no total, no grouping, no scalar monthly value.
 */
final class MonthlyWorkforceDimensions
{
    /** Names/codes are the CURRENT catalog values at read time, never a historical snapshot. */
    public const LABEL_SEMANTICS = 'CURRENT_RECORDED_LABEL';

    /** @param  list<MonthlyDimensionPersonRow>  $persons  ordered by Person id (the S37 order) */
    public function __construct(
        public readonly string $monthStart,
        public readonly string $nextMonthStart,
        public readonly string $labelSemantics,
        public readonly array $persons,
    ) {}
}
