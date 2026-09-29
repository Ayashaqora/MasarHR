<?php

namespace App\Modules\HumanResources\Domain\Exceptions;

use RuntimeException;

/**
 * A transfer at date D cannot be applied because a Partial Secondment starts on or after D — the
 * conservative S28 rule: later-recorded history is never rewritten (docs/partial-secondment-foundation-specification.md §S30.13,
 * ADR-S30-008). Maps to 422 errors.effective_to, mirroring the S12/S16 transfer conflicts.
 */
final class InvalidPartialSecondmentEndDateException extends RuntimeException
{
    public function __construct(string $message = 'A partial secondment starts on or after this date; it cannot be closed at an earlier date.')
    {
        parent::__construct($message);
    }
}
