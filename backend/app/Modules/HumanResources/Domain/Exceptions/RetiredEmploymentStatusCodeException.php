<?php

namespace App\Modules\HumanResources\Domain\Exceptions;

use RuntimeException;

/** S34: wants_to_return / does_not_want_to_return are Return Intention, no longer recordable as an employment status. */
final class RetiredEmploymentStatusCodeException extends RuntimeException
{
    public function __construct(string $code)
    {
        parent::__construct("'{$code}' is a Return Intention value, not an employment status; record it as a Return Intention period instead.");
    }
}
