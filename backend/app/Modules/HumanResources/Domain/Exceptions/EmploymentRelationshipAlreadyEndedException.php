<?php

namespace App\Modules\HumanResources\Domain\Exceptions;

use RuntimeException;

/**
 * EndEmploymentRelationship was called against a relationship whose end_knowledge_state is
 * already KNOWN (spec §10/§11). Maps to 409 — ending is a one-way transition, never idempotently
 * re-appliable with different values.
 */
final class EmploymentRelationshipAlreadyEndedException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('This employment relationship has already ended.');
    }
}
