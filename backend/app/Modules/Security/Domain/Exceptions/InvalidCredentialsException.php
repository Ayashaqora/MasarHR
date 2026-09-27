<?php

namespace App\Modules\Security\Domain\Exceptions;

use RuntimeException;

/**
 * The single, deliberately generic failure for every login rejection reason (unknown username,
 * wrong password, disabled account). Never carry a per-reason message into the HTTP response —
 * that is exactly the enumeration/status leak §12 forbids.
 */
final class InvalidCredentialsException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Invalid credentials.');
    }
}
