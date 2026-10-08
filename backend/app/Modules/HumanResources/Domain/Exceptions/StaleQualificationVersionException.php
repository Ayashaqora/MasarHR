<?php

namespace App\Modules\HumanResources\Domain\Exceptions;

use RuntimeException;

/**
 * S48 (docs/person-qualification-history-foundation-specification.md §S48.5 step 3, D16): a
 * correction's `expected_version` did not match the qualification's actual current
 * `version_number` once the Person-then-qualification row lock was acquired. Nothing is written;
 * never retried automatically. Maps to 409, mirroring the existing StaleVersionException family
 * (Security/Organization/Reference modules) and PersonStaleVersionException.
 */
final class StaleQualificationVersionException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('This qualification has been changed since it was last read. Reload and try again.');
    }
}
