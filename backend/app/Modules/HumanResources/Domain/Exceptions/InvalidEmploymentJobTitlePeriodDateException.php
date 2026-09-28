<?php

namespace App\Modules\HumanResources\Domain\Exceptions;

use RuntimeException;

/**
 * The supplied effective_from for a new employment job title period is before the relationship's
 * own effective_from (a title MAY start on that date — ADR-S22-001 §5), or not strictly after the
 * effective_from of the latest recorded job title period (a backdated period may never be inserted
 * before, or on top of, later history — docs/employment-job-title-history-foundation-specification.md
 * §S22.9). Also surfaced from the employment_job_title_periods_period_check CHECK /
 * employment_job_title_periods_no_overlap EXCLUDE constraints — never an unmapped 500.
 */
final class InvalidEmploymentJobTitlePeriodDateException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('effective_from must not be before the employment relationship\'s own effective_from and must be strictly after the latest recorded job title period\'s effective_from.');
    }
}
