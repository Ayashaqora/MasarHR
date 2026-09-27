<?php

namespace App\Modules\HumanResources\Domain\Exceptions;

use RuntimeException;

/**
 * EndWorkplaceAssignment was called against a relationship with no currently open Workplace
 * Assignment period (docs/workplace-assignment-foundation-specification.md §S16.9) — mirrors
 * NoActiveFullSecondmentException's identical shape verbatim. Maps to 409: "this action does not
 * apply to the current state" is surfaced as an explicit conflict, never a silently-successful
 * no-op.
 */
final class NoActiveWorkplaceAssignmentException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('This employment relationship has no active workplace assignment to end.');
    }
}
