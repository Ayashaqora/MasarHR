<?php

namespace App\Modules\HumanResources\Domain\Exceptions;

use RuntimeException;

/**
 * The supplied effective_from for a new (or replacement) workplace assignment period is not
 * strictly after the relationship's own effective_from, or not strictly after the currently-open
 * assignment period's own effective_from when one is being replaced
 * (docs/workplace-assignment-foundation-specification.md §S16.9). Mirrors
 * InvalidFullSecondmentStartDateException's/InvalidPlacementPeriodDateException's identical
 * shape: surfaced either by application-level validation or by the
 * workplace_assignment_periods_period_check CHECK constraint (SQLSTATE 23514, translated via
 * PostgresErrorClassifier::isCheckViolation()) — not left to propagate as an unmapped 500.
 */
final class InvalidWorkplaceAssignmentStartDateException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('effective_from must be strictly after both the employment relationship\'s own effective_from and, when replacing one, the currently open workplace assignment period\'s effective_from.');
    }
}
