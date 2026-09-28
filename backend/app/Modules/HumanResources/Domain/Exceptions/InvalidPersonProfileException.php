<?php

namespace App\Modules\HumanResources\Domain\Exceptions;

use RuntimeException;

/**
 * A Person profile value failed a domain rule (docs/person-profile-foundation-specification.md
 * §S24.6–§S24.10): a blank full_name_ar/birth_place, a future birth_date, a gender or marital status
 * that is not ACTIVE at command time (or vanished — benign TOCTOU), or an update that supplies no
 * profile field at all. Carries the offending field so the API can report it as a standard 422
 * validation error. Profile values are never inferred from one another.
 */
final class InvalidPersonProfileException extends RuntimeException
{
    public function __construct(public readonly string $field, string $message)
    {
        parent::__construct($message);
    }
}
