<?php

namespace App\Modules\Reference\Domain\Exceptions;

use RuntimeException;

/** A reference value with this `code` already exists in this table. Maps to 422. */
final class DuplicateReferenceCodeException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('A reference value with this code already exists.');
    }
}
