<?php

namespace App\Modules\HumanResources\Application\Commands;

use App\Modules\HumanResources\Domain\Exceptions\InvalidWorkplaceAssignmentEndDateException;
use App\Modules\HumanResources\Domain\Exceptions\NoActiveWorkplaceAssignmentException;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\EmploymentRelationship;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\WorkplaceAssignmentPeriod;
use App\Modules\Platform\Infrastructure\Persistence\Postgres\PostgresErrorClassifier as Errors;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;

/**
 * Ends the currently active workplace assignment period for an Employment Relationship, with no
 * replacement (docs/workplace-assignment-foundation-specification.md §S16.9). Deliberately does
 * NOT reject a call against an already-ended relationship — mirrors EndFullSecondment's identical,
 * disclosed reasoning verbatim: closing a stale, still-open row is administrative correction of
 * existing data, not "starting something new against a dead episode." "Actual workplace"
 * reverting to the underlying S11/S12 state is achieved purely by
 * ResolveActualWorkplaceForRelationship no longer finding an open row here — this command performs
 * no extra write to any other table.
 *
 * The EmploymentRelationship is re-fetched fresh with lockForUpdate() as the first statement —
 * serializes this command against a concurrent StartWorkplaceAssignment/TransferEmployee/
 * StartFullSecondment/EndEmploymentRelationship call on the same relationship (spec §S16.18).
 */
final class EndWorkplaceAssignment
{
    /** @throws NoActiveWorkplaceAssignmentException|InvalidWorkplaceAssignmentEndDateException */
    public function handle(
        EmploymentRelationship $relationship,
        string $effectiveTo,
    ): WorkplaceAssignmentPeriod {
        EmploymentRelationship::query()
            ->where('id', $relationship->getKey())
            ->lockForUpdate()
            ->firstOrFail();

        $openPeriod = WorkplaceAssignmentPeriod::query()
            ->where('employment_relationship_id', $relationship->getKey())
            ->whereNull('effective_to')
            ->first();

        if ($openPeriod === null) {
            throw new NoActiveWorkplaceAssignmentException;
        }

        $newTo = Carbon::parse($effectiveTo);

        if ($newTo->lte($openPeriod->effective_from)) {
            throw new InvalidWorkplaceAssignmentEndDateException;
        }

        try {
            $openPeriod->update(['effective_to' => $effectiveTo]);
        } catch (QueryException $e) {
            if (Errors::isCheckViolation($e)) {
                throw new InvalidWorkplaceAssignmentEndDateException;
            }

            throw $e;
        }

        return $openPeriod->refresh();
    }
}
