<?php

namespace App\Modules\HumanResources\Application\Commands;

use App\Modules\HumanResources\Domain\Exceptions\DuplicateNationalIdException;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\Person;
use App\Modules\Platform\Infrastructure\Persistence\Postgres\PostgresErrorClassifier as Errors;
use Illuminate\Database\QueryException;

/**
 * Creates a Person from a National ID (spec §4/§5). The only normalization applied is trim() —
 * S09 invents no digit semantics, checksum, or nationality/geography rule (spec §5). Never
 * silently returns an existing Person on a duplicate: the caller is expected to have already
 * looked one up (FindPersonByNationalId) before deciding to create — this is what makes
 * "reappointment must reuse the existing Person" a structural property of the API shape rather
 * than an internal find-or-create shortcut (spec §13).
 */
final class CreatePerson
{
    /** @throws DuplicateNationalIdException */
    public function handle(string $nationalId): Person
    {
        $person = new Person(['national_id' => trim($nationalId)]);

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
