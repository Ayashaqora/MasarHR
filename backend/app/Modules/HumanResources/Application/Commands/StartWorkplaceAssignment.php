<?php

namespace App\Modules\HumanResources\Application\Commands;

use App\Modules\HumanResources\Domain\Exceptions\ActiveFullSecondmentAlreadyExistsException;
use App\Modules\HumanResources\Domain\Exceptions\ActivePartialSecondmentExistsException;
use App\Modules\HumanResources\Domain\Exceptions\EmploymentRelationshipAlreadyEndedException;
use App\Modules\HumanResources\Domain\Exceptions\InvalidWorkplaceAssignmentDecisionTypeException;
use App\Modules\HumanResources\Domain\Exceptions\InvalidWorkplaceAssignmentStartDateException;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\EmploymentRelationship;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\FullSecondmentPeriod;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\PartialSecondmentPeriod;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\WorkplaceAssignmentPeriod;
use App\Modules\Organization\Infrastructure\Persistence\Eloquent\OrganizationalUnit;
use App\Modules\Platform\Infrastructure\Persistence\Postgres\PostgresErrorClassifier as Errors;
use App\Modules\Reference\Infrastructure\Persistence\Eloquent\DecisionType;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

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
 * S28 (docs/movement-temporal-integrity-corrective-specification.md, ADR-S28-001) supersedes S16's
 * conservative cross-stream REJECT: a Full Secondment EFFECTIVE at the new effective_from (open,
 * or closed with a later effective_to) is TRUNCATED at that date and the assignment is created,
 * atomically. Both the cross-stream and the same-stream "previous assignment" checks are now
 * interval-aware (effective at the date), never "open row"; a previous assignment recorded with a
 * future effective_to is truncated exactly like an open one. Later history is never rewritten:
 * a secondment starting on or after the new date → 409 (ActiveFullSecondmentAlreadyExistsException);
 * an assignment starting on or after it → 422 (InvalidWorkplaceAssignmentStartDateException, the
 * unchanged S16 same-stream rejection).
 *
 * S30 (docs/partial-secondment-foundation-specification.md §S30.12, ADR-S30-007 rule 1): Partial
 * Secondment is a temporary workplace movement for this interaction — EVERY Partial Secondment
 * effective at the new effective_from (several may be, with disjoint weekdays) is truncated at it,
 * atomically, before the assignment is created; a Partial Secondment starting on or after the date
 * rejects (409, ActivePartialSecondmentExistsException) instead of being rewritten (rule 4).
 * Assignment and Partial Secondment never remain effective together.
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
     * @throws ActiveFullSecondmentAlreadyExistsException|InvalidWorkplaceAssignmentStartDateException|ActivePartialSecondmentExistsException
     */
    public function __construct(private readonly SupersedeTemporaryWorkplaceMovement $supersession) {}

    public function handle(
        EmploymentRelationship $relationship,
        OrganizationalUnit $destination,
        string $effectiveFrom,
        DecisionType $decisionType,
    ): WorkplaceAssignmentPeriod {
        return DB::transaction(fn (): WorkplaceAssignmentPeriod => $this->start($relationship, $destination, $effectiveFrom, $decisionType));
    }

    private function start(
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

        $newFrom = Carbon::parse($effectiveFrom);

        // effective_from is set exactly once, at CreateEmploymentRelationship, and is never
        // mutated afterward by any command in this codebase — comparing against it here carries
        // no concurrency risk despite being an application-level check rather than a database
        // constraint (mirrors S11/S12's identical, disclosed reasoning).
        if ($newFrom->lte($freshRelationship->effective_from)) {
            throw new InvalidWorkplaceAssignmentStartDateException;
        }

        $date = $newFrom->toDateString();
        $relationshipId = $freshRelationship->getKey();
        $crossStreamConflict = fn () => new ActiveFullSecondmentAlreadyExistsException;
        $sameStreamConflict = fn () => new InvalidWorkplaceAssignmentStartDateException;
        $partialConflict = fn () => new ActivePartialSecondmentExistsException;

        // ADR-S28-001: validate both streams before mutating either, then truncate whatever is
        // effective at the new date (a secondment — cross-stream; a previous assignment — the
        // unchanged S16 close-previous rule, now interval-aware).
        $this->supersession->assertSupersedable(FullSecondmentPeriod::class, $relationshipId, $date, $crossStreamConflict);
        $this->supersession->assertSupersedable(WorkplaceAssignmentPeriod::class, $relationshipId, $date, $sameStreamConflict);
        $this->supersession->assertSupersedable(PartialSecondmentPeriod::class, $relationshipId, $date, $partialConflict);
        $this->supersession->supersedeAt(FullSecondmentPeriod::class, $relationshipId, $date, $crossStreamConflict);
        $this->supersession->supersedeAllAt(PartialSecondmentPeriod::class, $relationshipId, $date, $partialConflict);
        $this->supersession->supersedeAt(WorkplaceAssignmentPeriod::class, $relationshipId, $date, $sameStreamConflict);

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
