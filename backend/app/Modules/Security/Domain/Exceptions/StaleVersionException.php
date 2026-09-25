<?php

namespace App\Modules\Security\Domain\Exceptions;

use RuntimeException;

/** Optimistic-concurrency conflict: the caller's expected version no longer matches. Maps to 409. */
final class StaleVersionException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('This record was changed by someone else. Reload and try again.');
    }
}
