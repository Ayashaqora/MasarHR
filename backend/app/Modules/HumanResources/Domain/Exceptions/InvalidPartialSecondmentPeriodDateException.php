<?php

namespace App\Modules\HumanResources\Domain\Exceptions;

use RuntimeException;

/**
 * The requested Partial Secondment period is invalid: it starts on or before the Employment
 * Relationship's own start (the S12/S16 movement rule), or its effective_to is not after its
 * effective_from (docs/partial-secondment-foundation-specification.md §S30.7, ADR-S30-002). Maps to 422 on the offending field.
 */
final class InvalidPartialSecondmentPeriodDateException extends RuntimeException
{
    public function __construct(
        public readonly string $field = 'effective_from',
        string $message = 'effective_from must be after the employment relationship start, and effective_to after effective_from.',
    ) {
        parent::__construct($message);
    }
}
