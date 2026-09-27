<?php

namespace App\Modules\HumanResources\Domain\Exceptions;

use RuntimeException;

/**
 * The supplied effective_from for a new organizational placement period is not strictly after the
 * relationship's own effective_from, or not strictly after the currently-open placement period's
 * own effective_from. Surfaced either by application-level validation (against the relationship's
 * own, immutable effective_from — spec §8 step 3) or by the
 * organizational_placement_periods_period_check CHECK constraint on the closing UPDATE (SQLSTATE
 * 23514, translated via PostgresErrorClassifier::isCheckViolation()) — not left to propagate as an
 * unmapped 500 (docs/organizational-placement-foundation-specification.md §9).
 */
final class InvalidPlacementPeriodDateException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('effective_from must be strictly after both the employment relationship\'s own effective_from and the currently open placement period\'s effective_from.');
    }
}
