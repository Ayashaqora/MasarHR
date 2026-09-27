<?php

namespace App\Modules\HumanResources\Domain\Exceptions;

use RuntimeException;

/**
 * The supplied effective_to is not strictly after the relationship's effective_from. Surfaced by
 * the employment_relationships_period_check CHECK constraint (SQLSTATE 23514), translated via
 * PostgresErrorClassifier::isCheckViolation() — caught here rather than left to propagate as an
 * unmapped 500 (spec §19: "422 validation failure ... invalid dates").
 */
final class InvalidEndDateException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('effective_to must be strictly after this relationship\'s effective_from.');
    }
}
