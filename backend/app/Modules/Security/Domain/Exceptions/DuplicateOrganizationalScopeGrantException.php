<?php

namespace App\Modules\Security\Domain\Exceptions;

use RuntimeException;

final class DuplicateOrganizationalScopeGrantException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('This principal already holds this organizational scope grant.');
    }
}
