<?php

namespace App\Modules\HumanResources\Application\Commands;

use App\Modules\HumanResources\Domain\Exceptions\ActiveFullSecondmentAlreadyExistsException;
use App\Modules\HumanResources\Domain\Exceptions\EmploymentRelationshipAlreadyEndedException;
use App\Modules\HumanResources\Domain\Exceptions\InvalidWorkplaceAssignmentDecisionTypeException;
use App\Modules\HumanResources\Domain\Exceptions\InvalidWorkplaceAssignmentStartDateException;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\EmploymentRelationship;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\FullSecondmentPeriod;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\WorkplaceAssignmentPeriod;
use App\Modules\Organization\Infrastructure\Persistence\Eloquent\OrganizationalUnit;
use App\Modules\Platform\Infrastructure\Persistence\Postgres\PostgresErrorClassifier as Errors;
use App\Modules\Reference\Infrastructure\Persistence\Eloquent\DecisionType;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;

/**
 * Starts (or replaces) one workplace assignment period for an Employment Relationship
 * (docs/workplace-assignment-foundation-specification.md §S16.9). Unlike S12's
 * StartFullSecondment, this command DOES auto-close an existing open assignment period when one
 * exists, atomically replacing it with the new one — mirrors S11's
 * RecordOrganizationalPlacementPeriod verbatim (spec §S16.8, movement interaction matrix pair
 * "Assignment → Assignment": ALLOW, CLOSE-PREVIOUS-THEN-START-NEW), per ADR-S16-001 §11's own
 * explicit authorization for this shape. It never writes to hr.organizational_placement_periods
 * (S11) or hr.employment_relationships (S09) — original workplace is untouched (spec §S16.2).
 *
 * Rejects outright — never silently closes it — when an active Full Secondment exists (spec
 * §S16.8, pair "Full Secondment → Assignment": REJECT), a conservative mutual exclusion chosen to
 * avoid inventing a cross-domain precedence rule between two movement mechanisms ADR-S16-001 §4
 * keeps conceptually separate.
 *
 * The EmploymentRelationship is re-fetched fresh with lockForUpdate() as the first statement here
 * — never trusted from whatever the caller passed in — exactly mirroring
 * RecordOrganizationalPlacementPeriod's/StartFullSecondment's own established discipline. This
 * also serializes this command against a concurrent EndWorkplaceAssignment/TransferEmployee/
 * StartFullSecondment/EndFullSecondment/EndEmploymentRelationship/
 * RecordOrganizationalPlacementPeriod call on the same relationship (spec §S16.18).
 *
 * `decision_type_id` is validated against a freshly re-fetched `ref.decision_types` row — never
 * trusted from whatever the caller/controller resolved moments earlier — for the identical
 * anti-TOCTOU reason TransferEmployee re-fetches its own DecisionType fresh. The stable technical
 * discriminator checked is `code === 'ASSIGNMENT'`, never `name_ar`/`name_en` display text
 * (ADR-S16-001 §14 explicit instruction).
 *
 * No `is_active` gate is applied to the destination OrganizationalUnit here — mirrors the explicit
 * S10/S11/S12 precedent of leaving inactive-target exclusion to the authorization layer rather
 * than inventing a second, domain-level active check.
 */
final class StartWorkplaceAssignment
{
    /**
     * @throws EmploymentRelationshipAlreadyEndedException|InvalidWorkplaceAssignmentDecisionTypeException
     * @throws ActiveFullSecondmentAlreadyExistsException|InvalidWorkplaceAssignmentStartDateException
     */
    public function handle(
        EmploymentRelationship $relationship,
        OrganizationalUnit $destination,
        string $effectiveFrom,
        DecisionType $decisionType,
    ): WorkplaceAssignmentPeriod {
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

        $freshDecisionType = DecisionType::query()->where('id', $decisionType->getKey())->first();

        if ($freshDecisionType === null || $freshDecisionType->code !== 'ASSIGNMENT' || ! $freshDecisionType->is_active) {
            throw new InvalidWorkplaceAssignmentDecisionTypeException;
        }

        // Spec §S16.8, pair "Full Secondment → Assignment": mutual exclusion, no invented
        // precedence between the two domains.
        $activeSecondment = FullSecondmentPeriod::query()
            ->where('employment_relationship_id', $freshRelationship->getKey())
            ->whereNull('effective_to')
            ->exists();

        if ($activeSecondment) {
            throw new ActiveFullSecondmentAlreadyExistsException;
        }

        $newFrom = Carbon::parse($effectiveFrom);

        // effective_from is set exactly once, at CreateEmploymentRelationship, and is never
        // mutated afterward by any command in this codebase — comparing against it here carries
        // no concurrency risk despite being an application-level check rather than a database
        // constraint (mirrors S11/S12's identical, disclosed reasoning).
        if ($newFrom->lte($freshRelationship->effective_from)) {
            throw new InvalidWorkplaceAssignmentStartDateException;
        }

        $openPeriod = WorkplaceAssignmentPeriod::query()
            ->where('employment_relationship_id', $freshRelationship->getKey())
            ->whereNull('effective_to')
            ->first();

        if ($openPeriod !== null && $newFrom->lte($openPeriod->effective_from)) {
            throw new InvalidWorkplaceAssignmentStartDateException;
        }

        if ($openPeriod !== null) {
            try {
                $openPeriod->update(['effective_to' => $effectiveFrom]);
            } catch (QueryException $e) {
                if (Errors::isCheckViolation($e)) {
                    throw new InvalidWorkplaceAssignmentStartDateException;
                }

                throw $e;
            }
        }

        $period = new WorkplaceAssignmentPeriod([
            'employment_relationship_id' => $freshRelationship->getKey(),
            'organizational_unit_id' => $freshDestination->getKey(),
            'effective_from' => $effectiveFrom,
            'effective_to' => null,
        ]);

        try {
            $period->save();
        } catch (QueryException $e) {
            if (Errors::isExclusionViolation($e) || Errors::isCheckViolation($e)) {
                throw new InvalidWorkplaceAssignmentStartDateException;
            }

            throw $e;
        }

        return $period->refresh();
    }
}
