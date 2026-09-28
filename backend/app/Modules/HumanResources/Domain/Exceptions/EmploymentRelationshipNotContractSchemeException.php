<?php

namespace App\Modules\HumanResources\Domain\Exceptions;

use RuntimeException;

/**
 * A contract period was requested for an Employment Relationship whose appointment type is not
 * CONTRACT (its employee_number_scheme is PERMANENT). Permanent employment carries no contract
 * lifecycle and is never converted into an artificial contract
 * (docs/employment-contract-foundation-specification.md §S21.5, ADR-S21-001 §4). The discriminator
 * is the scheme S09 records on the relationship row itself from ref.employment_types.code, never
 * a display label.
 */
final class EmploymentRelationshipNotContractSchemeException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Employment contract periods can only be recorded for a CONTRACT employment relationship.');
    }
}
