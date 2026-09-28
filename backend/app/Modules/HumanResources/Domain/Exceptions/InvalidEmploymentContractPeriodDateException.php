<?php

namespace App\Modules\HumanResources\Domain\Exceptions;

use RuntimeException;

/**
 * The supplied effective_from for a new employment contract period is before the relationship's own
 * effective_from (a contract MAY start on that date — ADR-S21-001 §5), or not strictly after the
 * effective_from of the latest recorded contract period (a backdated period may never be inserted
 * before, or on top of, later history — docs/employment-contract-foundation-specification.md
 * §S21.9). Also surfaced from the employment_contract_periods_period_check CHECK /
 * employment_contract_periods_no_overlap EXCLUDE constraints (SQLSTATE 23514/23P01, translated via
 * PostgresErrorClassifier) — never left to propagate as an unmapped 500.
 */
final class InvalidEmploymentContractPeriodDateException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('effective_from must not be before the employment relationship\'s own effective_from and must be strictly after the latest recorded contract period\'s effective_from.');
    }
}
