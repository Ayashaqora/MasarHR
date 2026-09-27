<?php

namespace App\Modules\HumanResources\Domain\Exceptions;

use RuntimeException;

/**
 * StartFullSecondment was called against a relationship that already has an open (unended)
 * Workplace Assignment period (docs/workplace-assignment-foundation-specification.md §S16.8,
 * movement interaction matrix pair "Assignment → Full Secondment": REJECT). An active assignment
 * and an active full secondment are never simultaneously open for the same relationship — a
 * conservative mutual exclusion chosen specifically to avoid inventing a cross-domain precedence
 * rule between two movement mechanisms ADR-S16-001 §4 keeps conceptually separate. Maps to 409:
 * a real conflict with current state, not a validation failure of the submitted data.
 */
final class ActiveWorkplaceAssignmentAlreadyExistsException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('This employment relationship already has an active workplace assignment; end it before starting a full secondment.');
    }
}
