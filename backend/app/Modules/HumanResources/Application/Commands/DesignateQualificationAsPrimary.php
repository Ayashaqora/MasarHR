<?php

namespace App\Modules\HumanResources\Application\Commands;

use App\Modules\HumanResources\Domain\Exceptions\PrimaryQualificationConflictException;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\Person;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\PersonQualification;
use App\Modules\Platform\Infrastructure\Persistence\Postgres\PostgresErrorClassifier as Errors;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * S41 (R1-D33/D49, docs/human-cadre-report-foundation-specification.md §S41.7): the one explicit way to change a Person's
 * Primary Qualification. In ONE transaction it locks the Person row (the repository convention), verifies the target belongs to
 * that Person, and — unless the target is already Primary (idempotent success, nothing written) — unsets the current Primary
 * and then sets the target. The order matters: the partial unique index person_qualifications_one_primary_unique is not
 * deferrable and stays the final invariant (at most one Primary per Person). Any failure rolls the whole change back, leaving
 * the previous Primary intact. Concurrent designations for one Person serialize on the Person lock. No qualification is
 * created, updated (other than the flag) or deleted.
 */
final class DesignateQualificationAsPrimary
{
    /** @throws ModelNotFoundException when the qualification does not belong to the Person (404) */
    public function handle(Person $person, PersonQualification $qualification): PrimaryQualificationDesignation
    {
        return DB::transaction(function () use ($person, $qualification): PrimaryQualificationDesignation {
            $freshPerson = Person::query()->where('id', $person->getKey())->lockForUpdate()->firstOrFail();

            $target = PersonQualification::query()
                ->where('id', $qualification->getKey())
                ->where('person_id', $freshPerson->getKey())
                ->firstOrFail();

            $currentId = PersonQualification::query()
                ->where('person_id', $freshPerson->getKey())
                ->where('is_primary', true)
                ->value('id');

            if ($currentId === $target->getKey()) {
                return new PrimaryQualificationDesignation($target, $currentId, false);
            }

            try {
                if ($currentId !== null) {
                    PersonQualification::query()->where('id', $currentId)->update(['is_primary' => false]);
                }
                PersonQualification::query()->where('id', $target->getKey())->update(['is_primary' => true]);
            } catch (QueryException $e) {
                if (Errors::isUniqueViolation($e)) {
                    throw new PrimaryQualificationConflictException;
                }

                throw $e;
            }

            return new PrimaryQualificationDesignation($target->refresh(), $currentId, true);
        });
    }
}
