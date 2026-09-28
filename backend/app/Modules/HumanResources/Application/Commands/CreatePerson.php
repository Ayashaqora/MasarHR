<?php

namespace App\Modules\HumanResources\Application\Commands;

use App\Modules\HumanResources\Domain\Exceptions\DuplicateNationalIdException;
use App\Modules\HumanResources\Domain\Exceptions\InvalidPersonProfileException;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\Person;
use App\Modules\Platform\Infrastructure\Persistence\Postgres\PostgresErrorClassifier as Errors;
use App\Modules\Reference\Infrastructure\Persistence\Eloquent\Gender;
use App\Modules\Reference\Infrastructure\Persistence\Eloquent\MaritalStatus;
use Illuminate\Database\QueryException;

/**
 * Creates a Person from a National ID (spec §4/§5). The only normalization applied is trim() —
 * S09 invents no digit semantics, checksum, or nationality/geography rule (spec §5). Never
 * silently returns an existing Person on a duplicate: the caller is expected to have already
 * looked one up (FindPersonByNationalId) before deciding to create — this is what makes
 * "reappointment must reuse the existing Person" a structural property of the API shape rather
 * than an internal find-or-create shortcut (spec §13).
 *
 * S24 (docs/person-profile-foundation-specification.md §S24.6, ADR-S24-001): every NEW Person now
 * also requires full_name_ar, gender, marital status and birth_date (birth_place optional),
 * validated by PersonProfileValidator. This is a creation invariant only — legacy pre-S24 Persons
 * keep NULL profile values. The S09 national_id rules above are unchanged.
 */
final class CreatePerson
{
    public function __construct(private readonly PersonProfileValidator $profile) {}

    /** @throws DuplicateNationalIdException|InvalidPersonProfileException */
    public function handle(
        string $nationalId,
        string $fullNameAr,
        Gender $gender,
        MaritalStatus $maritalStatus,
        string $birthDate,
        ?string $birthPlace = null,
    ): Person {
        $person = new Person([
            'national_id' => trim($nationalId),
            'full_name_ar' => $this->profile->fullNameAr($fullNameAr),
            'gender_id' => $this->profile->activeGender($gender)->getKey(),
            'marital_status_id' => $this->profile->activeMaritalStatus($maritalStatus)->getKey(),
            'birth_date' => $this->profile->birthDate($birthDate),
            'birth_place' => $this->profile->birthPlace($birthPlace),
        ]);

        try {
            $person->save();
        } catch (QueryException $e) {
            if (Errors::isUniqueViolation($e)) {
                throw new DuplicateNationalIdException;
            }

            throw $e;
        }

        return $person;
    }
}
