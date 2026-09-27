<?php

namespace App\Modules\HumanResources\Domain\Exceptions;

use RuntimeException;

/**
 * The proposed [effective_from, effective_to) interval overlaps an existing Employment
 * Relationship for the same Person (spec §9). Surfaced by the database EXCLUDE constraint
 * (SQLSTATE 23P01), translated via PostgresErrorClassifier::isExclusionViolation() — identical
 * pattern to the Reference module's OverlappingBehaviorPeriodException (S05 spec §12). Maps to 409.
 */
final class OverlappingEmploymentRelationshipException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('This employment relationship overlaps an existing one for the same person.');
    }
}
