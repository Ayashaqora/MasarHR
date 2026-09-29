<?php

namespace App\Modules\HumanResources\Domain\Exceptions;

use RuntimeException;

/**
 * A Partial Secondment blocks the requested movement: a Full Secondment would overlap it in time
 * (ADR-S30-006, always a rejection), or a Workplace Assignment would have to rewrite a Partial
 * Secondment that starts on or after the assignment's start (ADR-S30-007 rule 4)
 * (docs/partial-secondment-foundation-specification.md §S30.11/§S30.12). Maps to 409.
 */
final class ActivePartialSecondmentExistsException extends RuntimeException
{
    public function __construct(string $message = 'A partial secondment of this employment relationship conflicts with this movement; it cannot be superseded or overlapped.')
    {
        parent::__construct($message);
    }
}
