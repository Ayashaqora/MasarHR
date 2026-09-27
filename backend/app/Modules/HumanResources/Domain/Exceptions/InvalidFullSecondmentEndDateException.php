<?php

namespace App\Modules\HumanResources\Domain\Exceptions;

use RuntimeException;

/**
 * The supplied effective_to for ending the currently open full secondment period is not strictly
 * after that period's own effective_from (spec §10 step 3). Surfaced either by application-level
 * validation or by the full_secondment_periods_period_check CHECK constraint on the closing
 * UPDATE (SQLSTATE 23514, translated via PostgresErrorClassifier::isCheckViolation()) — not left
 * to propagate as an unmapped 500 (docs/full-secondment-foundation-specification.md §11).
 */
final class InvalidFullSecondmentEndDateException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('effective_to must be strictly after the active full secondment period\'s own effective_from.');
    }
}
