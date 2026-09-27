<?php

namespace App\Modules\Security\Domain\Exceptions;

use RuntimeException;

final class DuplicateRoleCodeException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('A role with this code already exists.');
    }
}
