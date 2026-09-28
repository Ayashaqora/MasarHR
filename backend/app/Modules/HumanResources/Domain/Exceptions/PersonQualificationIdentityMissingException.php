<?php

namespace App\Modules\HumanResources\Domain\Exceptions;

use RuntimeException;

/**
 * Neither an academic degree nor a qualification type was supplied (ADR-S23-DECISIONS §5: both are
 * optional, independent dimensions, but at least one must be present). Also surfaced from the
 * person_qualifications_identity_present_check CHECK constraint. «بدون» (no qualification) is never
 * a qualification fact and is represented by the absence of rows, never by an empty one.
 */
final class PersonQualificationIdentityMissingException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('At least one of academic_degree_id or qualification_type_id is required.');
    }
}
