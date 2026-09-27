<?php

namespace App\Modules\HumanResources\Domain\Exceptions;

use RuntimeException;

/**
 * The supplied effective_to for ending a workplace assignment period is not strictly after that
 * open period's own effective_from (docs/workplace-assignment-foundation-specification.md
 * §S16.9). Mirrors InvalidFullSecondmentEndDateException's identical shape: surfaced either by
 * application-level validation or by the workplace_assignment_periods_period_check CHECK
 * constraint (SQLSTATE 23514, translated via PostgresErrorClassifier::isCheckViolation()) — not
 * left to propagate as an unmapped 500.
 */
final class InvalidWorkplaceAssignmentEndDateException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('effective_to must be strictly after the currently open workplace assignment period\'s effective_from.');
    }
}
