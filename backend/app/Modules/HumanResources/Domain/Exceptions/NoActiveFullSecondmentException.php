<?php

namespace App\Modules\HumanResources\Domain\Exceptions;

use RuntimeException;

/**
 * EndFullSecondment was called against a relationship with no currently open Full Secondment
 * period (spec §8.5/§10 step 2). Maps to 409, mirroring
 * EmploymentRelationshipAlreadyEndedException's own convention: "this action does not apply to
 * the current state" is surfaced as an explicit conflict, never a silently-successful no-op.
 */
final class NoActiveFullSecondmentException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('This employment relationship has no active full secondment to end.');
    }
}
