<?php

namespace App\Modules\HumanResources\Application\Commands;

use App\Modules\HumanResources\Domain\Exceptions\ActiveFullSecondmentAlreadyExistsException;
use App\Modules\HumanResources\Domain\Exceptions\ActiveWorkplaceAssignmentAlreadyExistsException;
use App\Modules\HumanResources\Domain\Exceptions\EmploymentRelationshipAlreadyEndedException;
use App\Modules\HumanResources\Domain\Exceptions\InvalidFullSecondmentStartDateException;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\EmploymentRelationship;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\FullSecondmentPeriod;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\WorkplaceAssignmentPeriod;
use App\Modules\Organization\Infrastructure\Persistence\Eloquent\OrganizationalUnit;
use App\Modules\Platform\Infrastructure\Persistence\Postgres\PostgresErrorClassifier as Errors;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;

/**
 * Starts one full secondment period for an Employment Relationship
 * (docs/full-secondment-foundation-specification.md §10). Unlike S10's
 * RecordEmploymentStatusPeriod and S11's RecordOrganizationalPlacementPeriod, this command never
 * auto-closes an existing open period — it rejects the call outright if one is already active
 * (spec §8.1). It never writes to hr.organizational_placement_periods (S11) or
 * hr.employment_relationships (S09) — original workplace is untouched (spec §15).
 *
 * The EmploymentRelationship is re-fetched fresh with lockForUpdate() as the first statement here
 * — never trusted from whatever the caller passed in — exactly mirroring
 * RecordOrganizationalPlacementPeriod's own established discipline. This also serializes this
 * command against a concurrent EndFullSecondment/EndEmploymentRelationship/
 * RecordOrganizationalPlacementPeriod call on the same relationship (spec §17).
 *
 * No `is_active` gate is applied to the destination OrganizationalUnit here — mirrors the
 * explicit S10/S11 precedent of leaving inactive-target exclusion to the authorization layer
 * rather than inventing a second, domain-level active check (spec §8.2).
 *
 * S16 (docs/workplace-assignment-foundation-specification.md §S16.8, movement interaction matrix
 * pair "Assignment → Full Secondment": REJECT): also rejects when an active Workplace Assignment
 * period exists — a conservative mutual exclusion between the two movement mechanisms, chosen
 * specifically to avoid inventing a cross-domain precedence rule ADR-S16-001 §4 does not supply.
 */
final class StartFullSecondment
{
    /**
     * @throws EmploymentRelationshipAlreadyEndedException|ActiveFullSecondmentAlreadyExistsException|InvalidFullSecondmentStartDateException
     * @throws ActiveWorkplaceAssignmentAlreadyExistsException
     */
    public function handle(
        EmploymentRelationship $relationship,
        OrganizationalUnit $destination,
        string $effectiveFrom,
    ): FullSecondmentPeriod {
        $freshRelationship = EmploymentRelationship::query()
            ->where('id', $relationship->getKey())
            ->lockForUpdate()
            ->firstOrFail();

        if ($freshRelationship->end_knowledge_state === 'KNOWN') {
            throw new EmploymentRelationshipAlreadyEndedException;
        }

        $freshDestination = OrganizationalUnit::query()
            ->where('id', $destination->getKey())
            ->firstOrFail();

        $alreadyActive = FullSecondmentPeriod::query()
            ->where('employment_relationship_id', $freshRelationship->getKey())
            ->whereNull('effective_to')
            ->exists();

        if ($alreadyActive) {
            throw new ActiveFullSecondmentAlreadyExistsException;
        }

        // S16 spec §S16.8, pair "Assignment → Full Secondment": mutual exclusion, no invented
        // precedence between the two domains.
        $activeAssignment = WorkplaceAssignmentPeriod::query()
            ->where('employment_relationship_id', $freshRelationship->getKey())
            ->whereNull('effective_to')
            ->exists();

        if ($activeAssignment) {
            throw new ActiveWorkplaceAssignmentAlreadyExistsException;
        }

        $newFrom = Carbon::parse($effectiveFrom);

        // effective_from is set exactly once, at CreateEmploymentRelationship, and is never
        // mutated afterward by any command in this codebase — comparing against it here carries
        // no concurrency risk despite being an application-level check rather than a database
        // constraint (spec §8.3, disclosed there).
        if ($newFrom->lte($freshRelationship->effective_from)) {
            throw new InvalidFullSecondmentStartDateException;
        }

        $period = new FullSecondmentPeriod([
            'employment_relationship_id' => $freshRelationship->getKey(),
            'organizational_unit_id' => $freshDestination->getKey(),
            'effective_from' => $effectiveFrom,
            'effective_to' => null,
        ]);

        try {
            $period->save();
        } catch (QueryException $e) {
            if (Errors::isExclusionViolation($e) || Errors::isCheckViolation($e)) {
                throw new InvalidFullSecondmentStartDateException;
            }

            throw $e;
        }

        return $period->refresh();
    }
}
