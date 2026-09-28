<?php

namespace App\Modules\HumanResources\Domain\Exceptions;

use RuntimeException;

/**
 * The `academic_degree_id` supplied to RecordPersonQualification did not resolve to an ACTIVE
 * ref.academic_degrees row at command time (ADR-S23-001 §6) — inactive, or not re-resolvable at all
 * (benign TOCTOU). Only NEW records are gated: a later deactivation never alters or hides an
 * existing qualification. Mirrors S20's InvalidEmploymentCategoryException shape.
 */
final class InvalidPersonQualificationAcademicDegreeException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('academic_degree_id must reference an active academic degree.');
    }
}
