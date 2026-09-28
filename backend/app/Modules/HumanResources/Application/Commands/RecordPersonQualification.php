<?php

namespace App\Modules\HumanResources\Application\Commands;

use App\Modules\HumanResources\Domain\Exceptions\DuplicatePersonQualificationException;
use App\Modules\HumanResources\Domain\Exceptions\InvalidPersonQualificationAcademicDegreeException;
use App\Modules\HumanResources\Domain\Exceptions\InvalidPersonQualificationTypeException;
use App\Modules\HumanResources\Domain\Exceptions\PersonQualificationIdentityMissingException;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\Person;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\PersonQualification;
use App\Modules\Platform\Infrastructure\Persistence\Postgres\PostgresErrorClassifier as Errors;
use App\Modules\Reference\Infrastructure\Persistence\Eloquent\AcademicDegree;
use App\Modules\Reference\Infrastructure\Persistence\Eloquent\QualificationType;
use Illuminate\Database\QueryException;

/**
 * Records one attained qualification fact for a Person
 * (docs/person-qualification-foundation-specification.md §S23.9, ADR-S23-001). Insert-only: it
 * never edits, replaces, or deletes an existing qualification, and recording one never touches
 * anything else about the Person or any Employment Relationship (category, job title, contract,
 * placement, status, supervisory data). No date is accepted or stored, and no primary/highest
 * designation is computed.
 *
 * Both references are optional independent dimensions, but at least one is required; each supplied
 * reference is re-fetched fresh and must be ACTIVE at command time. The exact-duplicate rule is
 * enforced by the person_qualifications_identity_unique constraint itself (translated to
 * DuplicatePersonQualificationException), so it also holds under concurrent requests without any
 * application-level pre-check or row lock.
 */
final class RecordPersonQualification
{
    /**
     * @throws PersonQualificationIdentityMissingException|DuplicatePersonQualificationException
     * @throws InvalidPersonQualificationAcademicDegreeException|InvalidPersonQualificationTypeException
     */
    public function handle(
        Person $person,
        ?AcademicDegree $academicDegree,
        ?QualificationType $qualificationType,
    ): PersonQualification {
        if ($academicDegree === null && $qualificationType === null) {
            throw new PersonQualificationIdentityMissingException;
        }

        $freshPerson = Person::query()->where('id', $person->getKey())->firstOrFail();

        $freshDegree = null;
        if ($academicDegree !== null) {
            $freshDegree = AcademicDegree::query()->where('id', $academicDegree->getKey())->first();

            if ($freshDegree === null || ! $freshDegree->is_active) {
                throw new InvalidPersonQualificationAcademicDegreeException;
            }
        }

        $freshType = null;
        if ($qualificationType !== null) {
            $freshType = QualificationType::query()->where('id', $qualificationType->getKey())->first();

            if ($freshType === null || ! $freshType->is_active) {
                throw new InvalidPersonQualificationTypeException;
            }
        }

        $qualification = new PersonQualification([
            'person_id' => $freshPerson->getKey(),
            'academic_degree_id' => $freshDegree?->getKey(),
            'qualification_type_id' => $freshType?->getKey(),
        ]);

        try {
            $qualification->save();
        } catch (QueryException $e) {
            if (Errors::isUniqueViolation($e)) {
                throw new DuplicatePersonQualificationException;
            }

            if (Errors::isCheckViolation($e)) {
                throw new PersonQualificationIdentityMissingException;
            }

            throw $e;
        }

        return $qualification->refresh();
    }
}
