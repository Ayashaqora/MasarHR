<?php

namespace App\Modules\HumanResources\Domain\Exceptions;

use RuntimeException;

/**
 * The exact same qualification identity (academic degree + qualification type, NULL counted as a
 * value) is already recorded for this Person (ADR-S23-001 §7). Surfaced from the
 * person_qualifications_identity_unique constraint (SQLSTATE 23505, via PostgresErrorClassifier) —
 * the database, not an application pre-check, is the source of truth, so concurrent duplicates are
 * rejected too. Maps to 409, mirroring DuplicateNationalIdException (S09).
 */
final class DuplicatePersonQualificationException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('This qualification is already recorded for the person.');
    }
}
