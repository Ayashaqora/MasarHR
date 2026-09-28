<?php

namespace App\Modules\HumanResources\Domain\Exceptions;

use RuntimeException;

/**
 * The supplied contractual_effective_to (the agreed contract term end, EXCLUSIVE — the frozen
 * half-open [from, to) convention) is not strictly after the period's own effective_from, so the
 * agreed term would be empty or reversed (docs/employment-contract-foundation-specification.md
 * §S21.7). Also surfaced from the employment_contract_periods_contractual_period_check /
 * known_term_bounds_check CHECK constraints.
 */
final class InvalidEmploymentContractTermException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('contractual_effective_to must be strictly after effective_from.');
    }
}
