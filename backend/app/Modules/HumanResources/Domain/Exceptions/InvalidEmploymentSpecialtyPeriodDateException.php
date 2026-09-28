<?php

namespace App\Modules\HumanResources\Domain\Exceptions;

use RuntimeException;

/**
 * The supplied effective_from for a new employee specialty period is before the relationship's own
 * effective_from (a specialty MAY start on that date), or not strictly after the effective_from of
 * the latest recorded specialty period (a backdated period may never be inserted before, or on top
 * of, later history — docs/employee-specialty-history-foundation-specification.md §S26.9, the S22
 * rule). Also surfaced from the employment_specialty_periods_period_check CHECK /
 * employment_specialty_periods_no_overlap EXCLUDE constraints — never an unmapped 500.
 */
final class InvalidEmploymentSpecialtyPeriodDateException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('effective_from must not be before the employment relationship\'s own effective_from and must be strictly after the latest recorded specialty period\'s effective_from.');
    }
}
