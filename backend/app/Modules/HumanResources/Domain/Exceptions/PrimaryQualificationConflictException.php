<?php

namespace App\Modules\HumanResources\Domain\Exceptions;

use RuntimeException;

/**
 * S41 (docs/human-cadre-report-foundation-specification.md §S41.7): the one-Primary-per-Person invariant (the partial unique
 * index person_qualifications_one_primary_unique) rejected a write. Unreachable while every writer holds the Person row lock;
 * kept so the database stays the final, cleanly reported protection.
 */
final class PrimaryQualificationConflictException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('The person already has a primary qualification; the write conflicted with a concurrent change.');
    }
}
