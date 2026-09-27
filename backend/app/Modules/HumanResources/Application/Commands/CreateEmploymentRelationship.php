<?php

namespace App\Modules\HumanResources\Application\Commands;

use App\Modules\HumanResources\Domain\Exceptions\DuplicatePermanentEmployeeNumberException;
use App\Modules\HumanResources\Domain\Exceptions\OverlappingEmploymentRelationshipException;
use App\Modules\HumanResources\Domain\Exceptions\PersonIsTerminalException;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\EmploymentRelationship;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\Person;
use App\Modules\Platform\Infrastructure\Persistence\Postgres\PostgresErrorClassifier as Errors;
use App\Modules\Reference\Infrastructure\Persistence\Eloquent\EmploymentType;
use Illuminate\Database\QueryException;
use InvalidArgumentException;

/**
 * Creates a new Employment Relationship for an EXISTING Person (spec §6/§8/§13). The route/
 * command shape — this command never accepts a National ID, only an already-resolved Person — is
 * what makes reappointment reuse the existing Person structurally rather than by convention: there
 * is no way to reach this command without first having looked the Person up or created them
 * explicitly (spec §13).
 *
 * PERMANENT/CONTRACT is the only distinction with employee-number consequences today (spec §7/
 * §8), resolved from ref.employment_types.code — never from employment_type_id alone, since a
 * partial UNIQUE index cannot itself branch on a joined table's mutable content.
 *
 * Both `$person` and `$employmentType` are re-read fresh inside this method — never trusted from
 * whatever the caller passed in — because this command always runs inside
 * AuditedCommandExecutor's transaction (spec §9/§13/§14: "revalidate inside the mutation
 * transaction ... never a pre-check alone"). This matters concretely for `is_terminal`: the
 * caller's copy of `$person` was loaded by route-model-binding *before* the transaction opened, so
 * trusting it would let a concurrent EndEmploymentRelationship(isTerminal: true) that commits in
 * between go unnoticed — `lockForUpdate()` here blocks until that concurrent transaction finishes
 * and then observes its committed result, closing that race rather than merely narrowing it.
 */
final class CreateEmploymentRelationship
{
    private const SCHEME_BY_CODE = [
        'permanent' => 'PERMANENT',
        'contract' => 'CONTRACT',
    ];

    /**
     * @throws PersonIsTerminalException|OverlappingEmploymentRelationshipException
     * @throws DuplicatePermanentEmployeeNumberException|InvalidArgumentException
     */
    public function handle(
        Person $person,
        EmploymentType $employmentType,
        string $effectiveFrom,
        ?string $employeeNumber,
    ): EmploymentRelationship {
        $freshPerson = Person::query()->where('id', $person->getKey())->lockForUpdate()->firstOrFail();

        if ($freshPerson->is_terminal) {
            throw new PersonIsTerminalException;
        }

        $freshEmploymentType = EmploymentType::query()->where('id', $employmentType->getKey())->firstOrFail();
        $scheme = self::SCHEME_BY_CODE[$freshEmploymentType->code] ?? null;

        if ($scheme === null) {
            throw new InvalidArgumentException(
                "Employment type code [{$freshEmploymentType->code}] has no employee-number scheme; only 'permanent' and 'contract' are supported by S09.",
            );
        }

        if ($scheme === 'PERMANENT') {
            if ($employeeNumber === null || trim($employeeNumber) === '') {
                throw new InvalidArgumentException('A permanent employment relationship requires an employee_number.');
            }
            $resolvedEmployeeNumber = trim($employeeNumber);
        } else {
            // CONTRACT: always the Person's own National ID, derived here — never trusted from
            // the caller (spec §8/§11).
            $resolvedEmployeeNumber = $freshPerson->national_id;
        }

        $relationship = new EmploymentRelationship([
            'person_id' => $freshPerson->getKey(),
            'employment_type_id' => $freshEmploymentType->getKey(),
            'employee_number' => $resolvedEmployeeNumber,
            'employee_number_scheme' => $scheme,
            'effective_from' => $effectiveFrom,
            'effective_to' => null,
            'end_knowledge_state' => 'NOT_APPLICABLE',
            'ended_terminally' => null,
        ]);

        try {
            $relationship->save();
        } catch (QueryException $e) {
            if (Errors::isExclusionViolation($e)) {
                throw new OverlappingEmploymentRelationshipException;
            }

            if (Errors::isUniqueViolation($e)) {
                throw new DuplicatePermanentEmployeeNumberException;
            }

            throw $e;
        }

        return $relationship;
    }
}
