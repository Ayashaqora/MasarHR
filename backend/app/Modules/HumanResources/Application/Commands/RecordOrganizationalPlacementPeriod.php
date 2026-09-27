<?php

namespace App\Modules\HumanResources\Application\Commands;

use App\Modules\HumanResources\Domain\Exceptions\EmploymentRelationshipAlreadyEndedException;
use App\Modules\HumanResources\Domain\Exceptions\InvalidPlacementPeriodDateException;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\EmploymentRelationship;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\OrganizationalPlacementPeriod;
use App\Modules\Organization\Infrastructure\Persistence\Eloquent\OrganizationalUnit;
use App\Modules\Platform\Infrastructure\Persistence\Postgres\PostgresErrorClassifier as Errors;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;

/**
 * Records one organizational-placement period for an Employment Relationship
 * (docs/organizational-placement-foundation-specification.md §8). Unlike S10's
 * RecordEmploymentStatusPeriod, this command has no downstream consequence step — Placement never
 * closes, reopens, or otherwise mutates the Employment Relationship it belongs to (spec §13).
 *
 * The EmploymentRelationship is re-fetched fresh with lockForUpdate() as the first statement here
 * — never trusted from whatever the caller passed in — exactly mirroring
 * CreateEmploymentRelationship's/RecordEmploymentStatusPeriod's own established discipline (spec
 * §8 step 1). This also serializes this command against a concurrent EndEmploymentRelationship or
 * RecordOrganizationalPlacementPeriod call on the same relationship (spec §15).
 *
 * No `is_active` gate is applied to the target OrganizationalUnit here — mirrors the explicit S10
 * precedent (itself citing S06 precedent) of leaving inactive-target exclusion to the
 * authorization layer rather than inventing a second, domain-level active check (spec §8 step 2).
 */
final class RecordOrganizationalPlacementPeriod
{
    /**
     * @throws EmploymentRelationshipAlreadyEndedException|InvalidPlacementPeriodDateException
     */
    public function handle(
        EmploymentRelationship $relationship,
        OrganizationalUnit $unit,
        string $effectiveFrom,
    ): OrganizationalPlacementPeriod {
        $freshRelationship = EmploymentRelationship::query()
            ->where('id', $relationship->getKey())
            ->lockForUpdate()
            ->firstOrFail();

        if ($freshRelationship->end_knowledge_state === 'KNOWN') {
            throw new EmploymentRelationshipAlreadyEndedException;
        }

        $freshUnit = OrganizationalUnit::query()
            ->where('id', $unit->getKey())
            ->firstOrFail();

        $newFrom = Carbon::parse($effectiveFrom);

        // effective_from is set exactly once, at CreateEmploymentRelationship, and is never
        // mutated afterward by any command in this codebase — comparing against it here carries
        // no concurrency risk despite being an application-level check rather than a database
        // constraint (spec §8 step 3, disclosed there).
        if ($newFrom->lte($freshRelationship->effective_from)) {
            throw new InvalidPlacementPeriodDateException;
        }

        $openPeriod = OrganizationalPlacementPeriod::query()
            ->where('employment_relationship_id', $freshRelationship->getKey())
            ->whereNull('effective_to')
            ->first();

        if ($openPeriod !== null && $newFrom->lte($openPeriod->effective_from)) {
            throw new InvalidPlacementPeriodDateException;
        }

        if ($openPeriod !== null) {
            try {
                $openPeriod->update(['effective_to' => $effectiveFrom]);
            } catch (QueryException $e) {
                if (Errors::isCheckViolation($e)) {
                    throw new InvalidPlacementPeriodDateException;
                }

                throw $e;
            }
        }

        $period = new OrganizationalPlacementPeriod([
            'employment_relationship_id' => $freshRelationship->getKey(),
            'organizational_unit_id' => $freshUnit->getKey(),
            'effective_from' => $effectiveFrom,
            'effective_to' => null,
        ]);

        try {
            $period->save();
        } catch (QueryException $e) {
            if (Errors::isExclusionViolation($e) || Errors::isCheckViolation($e)) {
                throw new InvalidPlacementPeriodDateException;
            }

            throw $e;
        }

        return $period->refresh();
    }
}
