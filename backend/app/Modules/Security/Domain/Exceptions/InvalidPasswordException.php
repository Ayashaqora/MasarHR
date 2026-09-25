<?php

namespace App\Modules\Security\Domain\Exceptions;

use RuntimeException;

final class InvalidPasswordException extends RuntimeException
{
    /** @param list<string> $violations */
    public function __construct(public readonly array $violations)
    {
        parent::__construct('Password does not meet policy requirements.');
    }
}
