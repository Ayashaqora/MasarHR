<?php

namespace App\Modules\Organization\Domain\Exceptions;

use RuntimeException;

/**
 * Optimistic-concurrency conflict: the caller's expected version no longer matches. Maps to 409.
 * Independent of Security's and Reference's own StaleVersionException classes — the Organization
 * module is its own module and does not import another module's domain exceptions (mirrors the
 * Reference module's S05 §14 precedent for its own copy of this exception).
 */
final class StaleVersionException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('This record was changed by someone else. Reload and try again.');
    }
}
