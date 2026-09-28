<?php

namespace App\Modules\HumanResources\Domain\Exceptions;

use RuntimeException;

/**
 * The supplied effective_from for a new employment category period is before the relationship's
 * own effective_from (a day-one category, ON that date, is allowed — ADR-S20-001 CA-01), or not
 * strictly after the effective_from of the latest
 * category period already recorded for that relationship (a backdated period may never be
 * inserted before, or on top of, later history — docs/employment-category-history-foundation-
 * specification.md §S20.9). Surfaced either by application-level validation or by the
 * employment_category_periods_period_check CHECK / employment_category_periods_no_overlap EXCLUDE
 * constraints (SQLSTATE 23514/23P01, translated via PostgresErrorClassifier) — never left to
 * propagate as an unmapped 500. Mirrors InvalidPlacementPeriodDateException's (S11) shape exactly.
 */
final class InvalidEmploymentCategoryPeriodDateException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('effective_from must not be before the employment relationship\'s own effective_from and must be strictly after the latest recorded employment category period\'s effective_from.');
    }
}
