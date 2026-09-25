<?php

namespace App\Modules\Security\Domain\Exceptions;

use RuntimeException;

/** Self-service ChangePassword: the supplied current_password did not match. Maps to 422. */
final class IncorrectCurrentPasswordException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('The current password is incorrect.');
    }
}
