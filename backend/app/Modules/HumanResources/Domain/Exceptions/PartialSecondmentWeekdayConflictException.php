<?php

namespace App\Modules\HumanResources\Domain\Exceptions;

use RuntimeException;

/**
 * Another Partial Secondment of the same Employment Relationship overlaps the requested period in
 * time AND shares at least one allocated weekday (docs/partial-secondment-foundation-specification.md §S30.10, ADR-S30-005). No winner is
 * chosen and nothing is truncated. Maps to 409.
 */
final class PartialSecondmentWeekdayConflictException extends RuntimeException
{
    public function __construct(string $message = 'Another partial secondment of this employment relationship already allocates one of these weekdays during this period.')
    {
        parent::__construct($message);
    }
}
