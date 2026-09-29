<?php

namespace App\Modules\HumanResources\Domain\Exceptions;

use RuntimeException;

/**
 * The weekday selection supplied to RecordWorkSchedulePeriod is empty, contains a duplicate, or
 * names a value that is not one of the seven structural ref.weekdays codes
 * (docs/work-schedule-foundation-specification.md §S29.7). A recorded schedule always names at
 * least one weekday; "no schedule" is represented only by the absence of a period.
 */
final class InvalidWorkScheduleWeekdaysException extends RuntimeException
{
    public function __construct(string $message = 'weekdays must be a non-empty list of distinct weekday codes (MONDAY … SUNDAY).')
    {
        parent::__construct($message);
    }
}
