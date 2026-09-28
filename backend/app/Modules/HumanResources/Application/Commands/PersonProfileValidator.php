<?php

namespace App\Modules\HumanResources\Application\Commands;

use App\Modules\HumanResources\Domain\Exceptions\InvalidPersonProfileException;
use App\Modules\Reference\Infrastructure\Persistence\Eloquent\Gender;
use App\Modules\Reference\Infrastructure\Persistence\Eloquent\MaritalStatus;
use Illuminate\Support\Carbon;

/**
 * The shared S24 profile rules used by CreatePerson and UpdatePersonProfile
 * (docs/person-profile-foundation-specification.md §S24.6–§S24.10, ADR-S24-001). Deliberately
 * minimal: trim() is the only normalization (the S09 national_id precedent); no word-count,
 * name-part, script, age-range or geography rule is invented; gender and marital status are
 * validated independently and never inferred from each other or from any text. References are
 * re-fetched fresh and must be ACTIVE for a new assignment.
 */
final class PersonProfileValidator
{
    public function fullNameAr(string $value): string
    {
        $trimmed = trim($value);

        if ($trimmed === '') {
            throw new InvalidPersonProfileException('full_name_ar', 'full_name_ar must not be blank.');
        }

        return $trimmed;
    }

    public function birthPlace(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);

        if ($trimmed === '') {
            throw new InvalidPersonProfileException('birth_place', 'birth_place must not be blank; omit it or send null when unknown.');
        }

        return $trimmed;
    }

    public function birthDate(string $value): string
    {
        $date = Carbon::parse($value)->startOfDay();

        if ($date->gt(Carbon::today())) {
            throw new InvalidPersonProfileException('birth_date', 'birth_date must not be in the future.');
        }

        return $date->toDateString();
    }

    public function activeGender(Gender $gender): Gender
    {
        $fresh = Gender::query()->where('id', $gender->getKey())->first();

        if ($fresh === null || ! $fresh->is_active) {
            throw new InvalidPersonProfileException('gender_id', 'gender_id must reference an active gender.');
        }

        return $fresh;
    }

    public function activeMaritalStatus(MaritalStatus $maritalStatus): MaritalStatus
    {
        $fresh = MaritalStatus::query()->where('id', $maritalStatus->getKey())->first();

        if ($fresh === null || ! $fresh->is_active) {
            throw new InvalidPersonProfileException('marital_status_id', 'marital_status_id must reference an active marital status.');
        }

        return $fresh;
    }
}
