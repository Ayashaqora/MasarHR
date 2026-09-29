<?php

namespace App\Modules\HumanResources\Domain\Exceptions;

use RuntimeException;

/**
 * A Partial Secondment would allocate a weekday the Employment Relationship's Work Schedule does not
 * contain on some date of the requested period, or the schedule is NOT_RECORDED for part of it
 * (docs/partial-secondment-foundation-specification.md §S30.9, ADR-S30-004). A missing schedule is never read as a default week. Maps to 422
 * errors.weekdays.
 */
final class PartialSecondmentOutsideWorkScheduleException extends RuntimeException
{
    public function __construct(string $message = 'Every allocated weekday must be in the recorded work schedule for the whole partial secondment period; no default week is assumed.')
    {
        parent::__construct($message);
    }
}
