<?php

namespace App\Modules\HumanResources\Domain\Exceptions;

use RuntimeException;

/**
 * The supplied effective_from for a new work schedule period is before the relationship's own
 * effective_from (a schedule MAY start on that date), or not strictly after the effective_from of
 * the latest recorded schedule period — a backdated period may never be inserted before, or on top
 * of, later history (docs/work-schedule-foundation-specification.md §S29.9, the S20/S22/S26 rule).
 * Also surfaced from the work_schedule_periods_period_check CHECK / work_schedule_periods_no_overlap
 * EXCLUDE constraints — never an unmapped 500.
 */
final class InvalidWorkSchedulePeriodDateException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('effective_from must not be before the employment relationship\'s own effective_from and must be strictly after the latest recorded work schedule period\'s effective_from.');
    }
}
