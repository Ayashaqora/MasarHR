<?php

namespace App\Modules\HumanResources\Domain\Exceptions;

use RuntimeException;

/**
 * A Work Schedule change from date D would remove a weekday still allocated by a Partial
 * Secondment effective on or after D (docs/partial-secondment-foundation-specification.md §S30.14, ADR-S30-010). The schedule change is
 * rejected before any mutation; the Partial Secondment is never silently changed or ended. Maps to
 * 422 errors.weekdays.
 */
final class WorkScheduleChangeInvalidatesPartialSecondmentException extends RuntimeException
{
    public function __construct(string $message = 'The new work schedule would remove a weekday that a partial secondment still allocates on or after this date.')
    {
        parent::__construct($message);
    }
}
