<?php

namespace App\Modules\HumanResources\Domain\Exceptions;

use RuntimeException;

/** S34: the supplied intention is not one of the two allowed values. */
final class InvalidReturnIntentionValueException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('intention must be WANTS_TO_RETURN or DOES_NOT_WANT_TO_RETURN.');
    }
}
