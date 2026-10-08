<?php

namespace App\Modules\HumanResources\Domain\Exceptions;

use RuntimeException;

/**
 * S48 (docs/person-qualification-history-foundation-specification.md §S48.5 step 5, §S48.7): a
 * supplied `obtained_on` is not a valid `YYYY-MM-DD` date, or names a date in the future — rejected
 * the same way an invalid `academic_degree_id` is. Maps to 422 with `errors: {obtained_on: [...]}`.
 */
final class InvalidPersonQualificationObtainedOnException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('obtained_on must be a valid date (YYYY-MM-DD) that is not in the future.');
    }
}
