<?php

namespace App\Modules\HumanResources\Domain\Exceptions;

use RuntimeException;

/**
 * S34: the effective_from of a Return Intention period is before the relationship's start, or would
 * rewrite already-recorded later history (a period starting on/after it exists), or the database
 * period/exclusion constraint rejected the interval.
 */
final class InvalidReturnIntentionPeriodDateException extends RuntimeException
{
    public function __construct(string $message = 'effective_from must not precede the employment relationship and must be after every already recorded Return Intention period start.')
    {
        parent::__construct($message);
    }
}
