<?php

namespace App\Modules\HumanResources\Application\Commands;

use App\Modules\HumanResources\Domain\Exceptions\DuplicatePersonQualificationException;
use App\Modules\HumanResources\Domain\Exceptions\InvalidPersonQualificationAcademicDegreeException;
use App\Modules\HumanResources\Domain\Exceptions\InvalidPersonQualificationObtainedOnException;
use App\Modules\HumanResources\Domain\Exceptions\InvalidPersonQualificationTypeException;
use App\Modules\HumanResources\Domain\Exceptions\PersonQualificationActorRequiredException;
use App\Modules\HumanResources\Domain\Exceptions\PersonQualificationIdentityMissingException;
use App\Modules\HumanResources\Domain\Exceptions\PrimaryQualificationConflictException;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\Person;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\PersonQualification;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\PersonQualificationVersion;
use App\Modules\Platform\Infrastructure\Persistence\Postgres\PostgresErrorClassifier as Errors;
use App\Modules\Reference\Infrastructure\Persistence\Eloquent\AcademicDegree;
use App\Modules\Reference\Infrastructure\Persistence\Eloquent\QualificationType;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Records one attained qualification fact for a Person
 * (docs/person-qualification-foundation-specification.md §S23.9, ADR-S23-001). Insert-only: it
 * never edits, replaces, or deletes an existing qualification identity, and recording one never
 * touches anything else about the Person or any Employment Relationship (category, job title,
 * contract, placement, status, supervisory data).
 *
 * S48 (docs/person-qualification-history-foundation-specification.md §S48.4): unchanged in its
 * validation, Person-row lock, duplicate check, and the automatic-first-Primary assignment
 * (R1-D49) — it never locks an existing qualification row, so it is already consistent with the
 * unified lock order without any change to its own logic. In the SAME transaction as the identity
 * insert, it now also inserts the qualification's version_number = 1 row (`reason = NULL`,
 * `created_by_principal_id` from the acting principal, `obtained_on` from the new optional input).
 * If this command ever failed to insert the version row, the transaction is rejected at COMMIT by
 * the parent-table deferred trigger (MA005, D32) — a qualification can never exist with zero
 * versions, independent of this command's own correctness. The exact-duplicate rule is enforced by
 * the person_qualification_versions_current_identity_unique constraint itself (translated to
 * DuplicatePersonQualificationException), so it also holds under concurrent requests without any
 * application-level pre-check or row lock (D14/D40 — scoped within this Person).
 */
final class RecordPersonQualification
{
    public function __construct(private readonly PersonQualificationValidator $validator) {}

    /**
     * @throws PersonQualificationActorRequiredException
     * @throws PersonQualificationIdentityMissingException|DuplicatePersonQualificationException
     * @throws InvalidPersonQualificationAcademicDegreeException|InvalidPersonQualificationTypeException
     * @throws InvalidPersonQualificationObtainedOnException
     */
    public function handle(
        Person $person,
        ?AcademicDegree $academicDegree,
        ?QualificationType $qualificationType,
        ?string $obtainedOn = null,
        ?string $actorPrincipalId = null,
    ): PersonQualificationRecording {
        // S48 (D43): created_by_principal_id = NULL is reserved for the documented migration-time
        // backfill alone — never for a write through this command. Rejected before anything else,
        // including the identity-presence check below, so a missing actor never reaches any write.
        if ($actorPrincipalId === null) {
            throw new PersonQualificationActorRequiredException;
        }

        if ($academicDegree === null && $qualificationType === null) {
            throw new PersonQualificationIdentityMissingException;
        }

        // Both inserts must commit together: the parent-table deferred trigger (MA005, D32) only
        // protects "never zero versions" if its check runs at the END of the SAME transaction that
        // contains both writes. Without this wrapper, an un-transacted call (any caller other than
        // AuditedCommandExecutor, which happens to wrap the whole HTTP request) would auto-commit
        // the identity insert alone, and the deferred check would fire immediately — before the
        // version row below is ever written — raising MA005 on every call. DesignateQualificationAsPrimary
        // and CorrectPersonQualification wrap themselves the same way for the same reason.
        return DB::transaction(function () use ($person, $academicDegree, $qualificationType, $obtainedOn, $actorPrincipalId): PersonQualificationRecording {
            // S41 (R1-D49): the Person row is locked first (the repository convention, CreateEmploymentRelationship), so two
            // concurrent "first" qualifications serialize and only one can become Primary.
            $freshPerson = Person::query()->where('id', $person->getKey())->lockForUpdate()->firstOrFail();

            $freshDegree = $this->validator->activeAcademicDegree($academicDegree);
            $freshType = $this->validator->activeQualificationType($qualificationType);
            $obtainedOn = $this->validator->obtainedOn($obtainedOn);

            $qualification = new PersonQualification([
                'person_id' => $freshPerson->getKey(),
                // S41 (R1-D49): the FIRST qualification of a Person is Primary automatically; a later one never replaces it.
                'is_primary' => ! PersonQualification::query()->where('person_id', $freshPerson->getKey())->exists(),
            ]);

            try {
                $qualification->save();
            } catch (QueryException $e) {
                if (Errors::isUniqueViolation($e)) {
                    throw new PrimaryQualificationConflictException;
                }

                throw $e;
            }

            $version = new PersonQualificationVersion([
                'person_qualification_id' => $qualification->getKey(),
                'person_id' => $freshPerson->getKey(),
                'version_number' => 1,
                'academic_degree_id' => $freshDegree?->getKey(),
                'qualification_type_id' => $freshType?->getKey(),
                'obtained_on' => $obtainedOn,
                'is_current' => true,
                'reason' => null,
                'created_by_principal_id' => $actorPrincipalId,
            ]);

            try {
                $version->save();
            } catch (QueryException $e) {
                if (Errors::isUniqueViolation($e)) {
                    throw new DuplicatePersonQualificationException;
                }

                if (Errors::isCheckViolation($e)) {
                    throw new PersonQualificationIdentityMissingException;
                }

                throw $e;
            }

            return new PersonQualificationRecording($qualification->refresh(), $version->refresh());
        });
    }
}
