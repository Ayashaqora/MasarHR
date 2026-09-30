<?php

namespace App\Modules\HumanResources\Application\Commands;

use App\Modules\HumanResources\Domain\Exceptions\EmploymentRelationshipAlreadyEndedException;
use App\Modules\HumanResources\Domain\Exceptions\InvalidFullSecondmentEndDateException;
use App\Modules\HumanResources\Domain\Exceptions\NoActiveFullSecondmentException;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\EmploymentRelationship;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\FullSecondmentPeriod;
use App\Modules\Platform\Infrastructure\Persistence\Postgres\PostgresErrorClassifier as Errors;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;

/**
 * Ends the currently active full secondment period for an Employment Relationship
 * (docs/full-secondment-foundation-specification.md §10). Deliberately does NOT reject a call
 * against an already-ended relationship (spec §7.3/§8.4) — closing a stale, still-open row is
 * administrative correction of existing data, not "starting something new against a dead
 * episode," so S11's "reject if already ended" rule is not mirrored here. "Actual workplace"
 * reverting to the underlying S11 placement state is achieved purely by
 * ResolveActualWorkplaceForRelationship no longer finding an open row here — this command performs
 * no extra write to any other table (spec §15).
 *
 * The EmploymentRelationship is re-fetched fresh with lockForUpdate() as the first statement —
 * serializes this command against a concurrent StartFullSecondment/EndFullSecondment/
 * RecordOrganizationalPlacementPeriod/EndEmploymentRelationship call on the same relationship
 * (spec §17).
 */
final class EndFullSecondment
{
    /** @throws EmploymentRelationshipAlreadyEndedException|NoActiveFullSecondmentException|InvalidFullSecondmentEndDateException */
    public function handle(
        EmploymentRelationship $relationship,
        string $effectiveTo,
    ): FullSecondmentPeriod {
        $freshRelationship = EmploymentRelationship::query()
            ->where('id', $relationship->getKey())
            ->lockForUpdate()
            ->firstOrFail();

        // S35: once the owning relationship has ended on/before the requested end date, no user command
        // may mutate a surviving movement (e.g. a Case 3 future row kept as recorded). The relationship
        // end itself no longer goes through this command (RelationshipEndMovementConsequences).
        if ($freshRelationship->end_knowledge_state === 'KNOWN'
            && $freshRelationship->effective_to !== null
            && Carbon::parse($effectiveTo)->gte($freshRelationship->effective_to)) {
            throw new EmploymentRelationshipAlreadyEndedException;
        }

        $openPeriod = FullSecondmentPeriod::query()
            ->where('employment_relationship_id', $relationship->getKey())
            ->whereNull('effective_to')
            ->first();

        if ($openPeriod === null) {
            throw new NoActiveFullSecondmentException;
        }

        $newTo = Carbon::parse($effectiveTo);

        if ($newTo->lte($openPeriod->effective_from)) {
            throw new InvalidFullSecondmentEndDateException;
        }

        try {
            $openPeriod->update(['effective_to' => $effectiveTo]);
        } catch (QueryException $e) {
            if (Errors::isCheckViolation($e)) {
                throw new InvalidFullSecondmentEndDateException;
            }

            throw $e;
        }

        return $openPeriod->refresh();
    }
}
