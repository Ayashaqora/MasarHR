<?php

namespace App\Modules\HumanResources\Domain\Exceptions;

use RuntimeException;

/**
 * The weekday allocation supplied to RecordPartialSecondmentPeriod is empty, contains a duplicate, or
 * names a value that is not one of the seven structural ref.weekdays codes (docs/partial-secondment-foundation-specification.md §S30.8,
 * ADR-S30-003). Maps to 422 errors.weekdays.
 */
final class InvalidPartialSecondmentWeekdaysException extends RuntimeException
{
    public function __construct(string $message = 'weekdays must be a non-empty list of distinct weekday codes (MONDAY … SUNDAY).')
    {
        parent::__construct($message);
    }
}
