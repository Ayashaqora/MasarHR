<?php

namespace App\Modules\Security\Domain\Exceptions;

use RuntimeException;

final class DuplicateUsernameException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('A principal with this username already exists.');
    }
}
