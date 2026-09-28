<?php

namespace App\Modules\HumanResources\Domain\Exceptions;

use RuntimeException;

/**
 * The supplied effective_from for a new full secondment period is not strictly after the
 * relationship's own effective_from (spec §8.3/§10 step 4). Surfaced either by application-level
 * validation or by the full_secondment_periods_period_check CHECK constraint on insert (SQLSTATE
 * 23514, translated via PostgresErrorClassifier::isCheckViolation()) — not left to propagate as an
 * unmapped 500 (docs/full-secondment-foundation-specification.md §11).
 */
final class InvalidFullSecondmentStartDateException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('effective_from must be strictly after this employment relationship\'s own effective_from.');
    }
}
