<?php

namespace App\Modules\HumanResources\Application\Commands;

use App\Modules\HumanResources\Domain\Exceptions\DuplicatePersonQualificationException;
use App\Modules\HumanResources\Domain\Exceptions\InvalidPersonQualificationAcademicDegreeException;
use App\Modules\HumanResources\Domain\Exceptions\InvalidPersonQualificationObtainedOnException;
use App\Modules\HumanResources\Domain\Exceptions\InvalidPersonQualificationTypeException;
use App\Modules\HumanResources\Domain\Exceptions\NoOpQualificationCorrectionException;
use App\Modules\HumanResources\Domain\Exceptions\PersonQualificationActorRequiredException;
use App\Modules\HumanResources\Domain\Exceptions\PersonQualificationIdentityMissingException;
use App\Modules\HumanResources\Domain\Exceptions\StaleQualificationVersionException;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\Person;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\PersonQualification;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\PersonQualificationVersion;
use App\Modules\Platform\Infrastructure\Persistence\Postgres\PostgresErrorClassifier as Errors;
use App\Modules\Reference\Infrastructure\Persistence\Eloquent\AcademicDegree;
use App\Modules\Reference\Infrastructure\Persistence\Eloquent\QualificationType;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * S48 (docs/person-qualification-history-foundation-specification.md §S48.5, D10/D11/D14/D16/D25/D40):
 * the ONLY other writer, ever, of a qualification's degree/type/obtained-on values besides
 * RecordPersonQualification. Never touches `is_primary` (D12) and never inserts a new
 * `hr.person_qualifications` row (D10).
 *
 * 1. Locks the Person row, then the qualification's own row, in that order (D25) — never the
 *    reverse. This serialises CorrectPersonQualification against Record/DesignatePrimary for the
 *    SAME Person only; it is not, and was never meant to be, a cross-Person mechanism (D40).
 * 2. Re-reads the current version inside the lock.
 * 3. Staleness (D16): `expectedVersion` must match the current version's own `version_number`,
 *    or the whole call is rejected (409) with nothing written, never retried automatically.
 * 4. No-op (D11/D16): an identical proposed (academic_degree_id, qualification_type_id,
 *    obtained_on), compared NULL-safely, is rejected (422) with nothing written.
 * 5. Re-validates exactly as RecordPersonQualification does (shared PersonQualificationValidator).
 * 6. UPDATEs the current version to is_current = false, then INSERTs the new version
 *    (version_number = previous + 1, is_current = true) — the qualification briefly has ZERO
 *    current versions between these two statements, tolerated by the deferred "at least one
 *    current" trigger until COMMIT (D32, §S48.8).
 * 7. The INSERT's own current-identity constraint — not an application pre-check — is what
 *    actually rejects a duplicate under concurrency, scoped WITHIN this Person (D40).
 */
final class CorrectPersonQualification
{
    public function __construct(private readonly PersonQualificationValidator $validator) {}

    /**
     * @throws PersonQualificationActorRequiredException
     * @throws ModelNotFoundException when the qualification does not belong to the Person (404)
     * @throws StaleQualificationVersionException|NoOpQualificationCorrectionException
     * @throws InvalidPersonQualificationAcademicDegreeException|InvalidPersonQualificationTypeException
     * @throws InvalidPersonQualificationObtainedOnException|DuplicatePersonQualificationException
     * @throws PersonQualificationIdentityMissingException
     */
    public function handle(
        Person $person,
        PersonQualification $qualification,
        int $expectedVersion,
        ?AcademicDegree $academicDegree,
        ?QualificationType $qualificationType,
        ?string $obtainedOn,
        string $reason,
        ?string $actorPrincipalId,
    ): PersonQualificationCorrection {
        // S48 (D43): created_by_principal_id = NULL is reserved for the documented migration-time
        // backfill alone — never for a write through this command. Rejected before the Person/
        // qualification locks are even taken, so a missing actor never reaches any write.
        if ($actorPrincipalId === null) {
            throw new PersonQualificationActorRequiredException;
        }

        return DB::transaction(function () use (
            $person, $qualification, $expectedVersion, $academicDegree, $qualificationType,
            $obtainedOn, $reason, $actorPrincipalId,
        ): PersonQualificationCorrection {
            $freshPerson = Person::query()->where('id', $person->getKey())->lockForUpdate()->firstOrFail();

            $freshQualification = PersonQualification::query()
                ->where('id', $qualification->getKey())
                ->where('person_id', $freshPerson->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $current = PersonQualificationVersion::query()
                ->where('person_qualification_id', $freshQualification->getKey())
                ->where('is_current', true)
                ->firstOrFail();

            if ($expectedVersion !== $current->version_number) {
                throw new StaleQualificationVersionException;
            }

            $freshDegree = $this->validator->activeAcademicDegree($academicDegree);
            $freshType = $this->validator->activeQualificationType($qualificationType);
            $cleanObtainedOn = $this->validator->obtainedOn($obtainedOn);

            $proposedDegreeId = $freshDegree?->getKey();
            $proposedTypeId = $freshType?->getKey();
            $currentObtainedOn = $current->obtained_on?->toDateString();

            if ($proposedDegreeId === $current->academic_degree_id
                && $proposedTypeId === $current->qualification_type_id
                && $cleanObtainedOn === $currentObtainedOn
            ) {
                throw new NoOpQualificationCorrectionException;
            }

            // Momentary zero current versions between here and the INSERT below — tolerated by
            // the deferred "at least one current" trigger until COMMIT (D32).
            $current->update(['is_current' => false]);

            $newVersion = new PersonQualificationVersion([
                'person_qualification_id' => $freshQualification->getKey(),
                'person_id' => $freshPerson->getKey(),
                'version_number' => $current->version_number + 1,
                'academic_degree_id' => $proposedDegreeId,
                'qualification_type_id' => $proposedTypeId,
                'obtained_on' => $cleanObtainedOn,
                'is_current' => true,
                'reason' => $reason,
                'created_by_principal_id' => $actorPrincipalId,
            ]);

            try {
                $newVersion->save();
            } catch (QueryException $e) {
                if (Errors::isUniqueViolation($e)) {
                    throw new DuplicatePersonQualificationException;
                }

                if (Errors::isCheckViolation($e)) {
                    throw new PersonQualificationIdentityMissingException;
                }

                throw $e;
            }

            return new PersonQualificationCorrection(
                $freshQualification->refresh(),
                $current->refresh(),
                $newVersion->refresh(),
            );
        });
    }
}
