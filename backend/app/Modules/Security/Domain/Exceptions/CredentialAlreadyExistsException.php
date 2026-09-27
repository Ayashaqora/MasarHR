<?php

namespace App\Modules\Security\Domain\Exceptions;

use RuntimeException;

/** SetInitialPassword refuses to run twice; use ResetPasswordAdministratively to replace a credential. */
final class CredentialAlreadyExistsException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('This principal already has a password credential.');
    }
}
