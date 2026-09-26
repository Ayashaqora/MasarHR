<?php

namespace App\Modules\HumanResources\Application\Commands;

use App\Modules\HumanResources\Domain\Exceptions\EmploymentRelationshipAlreadyEndedException;
use App\Modules\HumanResources\Domain\Exceptions\InvalidEndDateException;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\EmploymentRelationship;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\Person;
use App\Modules\Platform\Infrastructure\Persistence\Postgres\PostgresErrorClassifier as Errors;
use Illuminate\Database\QueryException;

/**
 * The one, narrow, explicit command for ending a relationship (spec §10/§15) — S09 does not model
 * *which* detailed status caused the ending (that is S06's ref.employment_status_details/
 * _behaviors stream, deferred). `isTerminal` is the single bit of information that stream will
 * eventually own that S09's own reappointment invariant structurally needs now, so it is the only
 * bit captured here.
 *
 * Optimistic-concurrency guarded via the same scoped conditional UPDATE shape as every prior
 * module (RenameOrganizationalUnit, etc.), but with a single collapsed failure exception rather
 * than a separate StaleVersionException: ending is the *only* mutation this command ever applies
 * to an EmploymentRelationship, and it is one-way (NOT_APPLICABLE/UNKNOWN_LEGACY → KNOWN). There
 * is therefore no scenario in which the scoped UPDATE matches zero rows for a reason other than
 * "someone already ended this relationship" — a genuine version race and "already ended" are the
 * same fact here, not two distinct outcomes, so a separate StaleVersionException class would be
 * unreachable dead code for this module in S09 v1 (spec §11 — disclosed rather than left as an
 * untested branch). When isTerminal is true, also permanently marks the Person terminal in the
 * same transaction — there is no command to reverse this (spec §10).
 */
final class EndEmploymentRelationship
{
    /** @throws EmploymentRelationshipAlreadyEndedException|InvalidEndDateException */
    public function handle(
        Person $person,
        EmploymentRelationship $relationship,
        int $expectedVersion,
        string $effectiveTo,
        bool $isTerminal,
    ): EmploymentRelationship {
        if ($relationship->end_knowledge_state === 'KNOWN') {
            throw new EmploymentRelationshipAlreadyEndedException;
        }

        try {
            $updated = EmploymentRelationship::query()
                ->where('id', $relationship->getKey())
                ->where('version', $expectedVersion)
                ->where('end_knowledge_state', '!=', 'KNOWN')
                ->update([
                    'effective_to' => $effectiveTo,
                    'end_knowledge_state' => 'KNOWN',
                    'ended_terminally' => $isTerminal,
                    'version' => $expectedVersion + 1,
                ]);
        } catch (QueryException $e) {
            // employment_relationships_period_check (spec §9/§19): effective_to must be strictly
            // after effective_from. Not pre-validated at the controller because the comparison is
            // against this specific relationship's own effective_from, which the database
            // constraint is the single source of truth for inside this transaction.
            if (Errors::isCheckViolation($e)) {
                throw new InvalidEndDateException;
            }

            throw $e;
        }

        if ($updated === 0) {
            EmploymentRelationship::query()->where('id', $relationship->getKey())->firstOrFail();

            throw new EmploymentRelationshipAlreadyEndedException;
        }

        if ($isTerminal && ! $person->is_terminal) {
            $person->forceFill(['is_terminal' => true, 'version' => $person->version + 1])->save();
        }

        return $relationship->refresh();
    }
}
