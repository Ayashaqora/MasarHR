<?php

namespace App\Modules\HumanResources\Domain\Exceptions;

use RuntimeException;

/**
 * A Person with this National ID already exists (spec §5/§11). Maps to 409 — the correct response
 * to a duplicate is "look it up", not "retry the create": CreatePerson never silently returns the
 * existing Person, since that would let a caller sidestep the National-ID-lookup-first workflow
 * the reappointment invariant depends on (spec §13).
 */
final class DuplicateNationalIdException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('A person with this national ID already exists.');
    }
}
