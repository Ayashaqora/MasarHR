<?php

namespace App\Modules\HumanResources\Domain\Exceptions;

use RuntimeException;

/**
 * StartFullSecondment was called against a relationship that already has an open (unended) Full
 * Secondment period (spec §8.1/§10 step 3). Unlike S10/S11's transition commands, starting a new
 * period here never auto-closes the existing one — the caller must explicitly end it first via
 * EndFullSecondment. Maps to 409: "No overlapping: full/full" (authorization §14) is a real
 * conflict with current state, not a validation failure of the submitted data.
 */
final class ActiveFullSecondmentAlreadyExistsException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('This employment relationship already has an active full secondment; end it before starting another.');
    }
}
