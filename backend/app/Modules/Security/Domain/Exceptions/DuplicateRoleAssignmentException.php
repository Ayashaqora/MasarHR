<?php

namespace App\Modules\Security\Domain\Exceptions;

use RuntimeException;

final class DuplicateRoleAssignmentException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('This role is already assigned to this principal.');
    }
}
