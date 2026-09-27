<?php

namespace App\Modules\HumanResources\Domain\Exceptions;

use RuntimeException;

/**
 * This PERMANENT employee number is already in use — either currently or historically, by any
 * Person (spec §8/§10). Surfaced by the partial unique index
 * employment_relationships_permanent_number_unique. Maps to 409.
 */
final class DuplicatePermanentEmployeeNumberException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('This permanent employee number is already in use and cannot be reassigned.');
    }
}
