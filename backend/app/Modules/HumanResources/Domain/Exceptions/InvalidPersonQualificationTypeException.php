<?php

namespace App\Modules\HumanResources\Domain\Exceptions;

use RuntimeException;

/**
 * The `qualification_type_id` supplied to RecordPersonQualification did not resolve to an ACTIVE
 * ref.qualification_types row at command time (ADR-S23-001 §6). Only NEW records are gated.
 */
final class InvalidPersonQualificationTypeException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('qualification_type_id must reference an active qualification type.');
    }
}
