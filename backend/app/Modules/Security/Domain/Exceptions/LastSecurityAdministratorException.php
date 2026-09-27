<?php

namespace App\Modules\Security\Domain\Exceptions;

use RuntimeException;

/**
 * Thrown when an operation would leave zero ACTIVE principals capable of security
 * administration/recovery (§17 of the S03 authorization). Maps to 409: it is a state conflict, not
 * a validation failure of the request payload itself.
 */
final class LastSecurityAdministratorException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('This action would leave no active principal capable of security administration.');
    }
}
