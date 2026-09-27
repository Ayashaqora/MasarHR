<?php

namespace App\Modules\Security\Domain\Exceptions;

use RuntimeException;

final class DuplicatePermissionGrantException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('This permission is already granted to this role.');
    }
}
