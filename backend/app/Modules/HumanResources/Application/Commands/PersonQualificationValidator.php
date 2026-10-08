<?php

namespace App\Modules\HumanResources\Application\Commands;

use App\Modules\HumanResources\Domain\Exceptions\InvalidPersonQualificationAcademicDegreeException;
use App\Modules\HumanResources\Domain\Exceptions\InvalidPersonQualificationObtainedOnException;
use App\Modules\HumanResources\Domain\Exceptions\InvalidPersonQualificationTypeException;
use App\Modules\Reference\Infrastructure\Persistence\Eloquent\AcademicDegree;
use App\Modules\Reference\Infrastructure\Persistence\Eloquent\QualificationType;
use Illuminate\Support\Carbon;

/**
 * The shared S23/S48 reference and `obtained_on` rules (docs/person-qualification-foundation-specification.md,
 * docs/person-qualification-history-foundation-specification.md §S48.5 step 5/§S48.7): used by both
 * RecordPersonQualification and CorrectPersonQualification so the two commands re-validate
 * identically (D26) — each supplied reference is re-fetched fresh and must be ACTIVE at command
 * time; `obtained_on` is optional, `YYYY-MM-DD` or `null`, never a future date, and is never
 * inferred from `created_at` or any other timestamp (D01/D04/§S48.7). Mirrors
 * PersonProfileValidator's shape for the same kind of shared, command-level re-validation.
 */
final class PersonQualificationValidator
{
    /** @throws InvalidPersonQualificationAcademicDegreeException */
    public function activeAcademicDegree(?AcademicDegree $academicDegree): ?AcademicDegree
    {
        if ($academicDegree === null) {
            return null;
        }

        $fresh = AcademicDegree::query()->where('id', $academicDegree->getKey())->first();

        if ($fresh === null || ! $fresh->is_active) {
            throw new InvalidPersonQualificationAcademicDegreeException;
        }

        return $fresh;
    }

    /** @throws InvalidPersonQualificationTypeException */
    public function activeQualificationType(?QualificationType $qualificationType): ?QualificationType
    {
        if ($qualificationType === null) {
            return null;
        }

        $fresh = QualificationType::query()->where('id', $qualificationType->getKey())->first();

        if ($fresh === null || ! $fresh->is_active) {
            throw new InvalidPersonQualificationTypeException;
        }

        return $fresh;
    }

    /** @throws InvalidPersonQualificationObtainedOnException */
    public function obtainedOn(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        try {
            $date = Carbon::createFromFormat('Y-m-d', $value)?->startOfDay();
        } catch (\Throwable) {
            $date = null;
        }

        if ($date === null || $date->format('Y-m-d') !== $value) {
            throw new InvalidPersonQualificationObtainedOnException;
        }

        if ($date->gt(Carbon::today())) {
            throw new InvalidPersonQualificationObtainedOnException;
        }

        return $date->toDateString();
    }
}
