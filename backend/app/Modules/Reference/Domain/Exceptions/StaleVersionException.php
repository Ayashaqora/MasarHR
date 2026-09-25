<?php

namespace App\Modules\Reference\Domain\Exceptions;

use RuntimeException;

/**
 * Optimistic-concurrency conflict: the caller's expected version no longer matches. Maps to 409.
 * Independent of App\Modules\Security\Domain\Exceptions\StaleVersionException — the Reference
 * module is its own module and does not import Security's domain exceptions (S05 §14).
 */
final class StaleVersionException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('This record was changed by someone else. Reload and try again.');
    }
}
