<?php

namespace App\Modules\HumanResources\Presentation\Http\Controllers;

use App\Modules\Audit\Application\AuditedCommandExecutor;
use App\Modules\Audit\Domain\AuditSpec;
use App\Modules\HumanResources\Application\Commands\SupersedeTemporaryWorkplaceMovement;
use App\Modules\HumanResources\Application\Commands\TransferEmployee;
use App\Modules\HumanResources\Domain\TransferResult;
use App\Modules\HumanResources\Infrastructure\Authorization\HumanResourcesPermissionCatalog as Perm;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\EmploymentRelationship;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\FullSecondmentPeriod;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\OrganizationalPlacementPeriod;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\PartialSecondmentPeriod;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\Person;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\WorkplaceAssignmentPeriod;
use App\Modules\HumanResources\Presentation\Http\Resources\TransferResource;
use App\Modules\Organization\Infrastructure\Persistence\Eloquent\OrganizationalUnit;
use App\Modules\Platform\Presentation\Http\Middleware\ResolveCommandContext;
use App\Modules\Reference\Infrastructure\Persistence\Eloquent\DecisionType;
use App\Modules\Security\Infrastructure\Authorization\ScopedAuthorizationChecker;
use App\Modules\Security\Infrastructure\Persistence\Eloquent\Principal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * S14 Transfer Foundation administration surface (docs/transfer-foundation-specification.md §20),
 * nested under an existing {person}/{employmentRelationship} — mirrors
 * OrganizationalPlacementPeriodController's (S11) and FullSecondmentPeriodController's (S12) own
 * nested shape. Composes S08's existing, unmodified ScopedAuthorizationChecker against up to
 * FOUR targets (spec §12.1, extending the S12 specification's own §12.1 "critical adversarial
 * point" resolution further, and extended again by S16 —
 * docs/workplace-assignment-foundation-specification.md §S16.14): the destination unit (always),
 * the current placement's "source" unit (if one is recorded), an active full secondment's unit
 * (if one will be closed as this transfer's consequence), and an active workplace assignment's
 * unit (if one will be closed as this transfer's consequence, S16) — every roster this action
 * visibly changes is checked, not only the narrower set each prior stage's own operation touched.
 * The checker itself is never modified — it is called up to four times with four different
 * targets, exactly as it was designed to be called. A relationship can never have both an active
 * secondment and an active assignment at once (S16 §S16.8 mutual exclusion), so at most one of
 * the last two checks is ever actually reached.
 */
class TransferController
{
    public function store(
        Request $request,
        Person $person,
        EmploymentRelationship $employmentRelationship,
        TransferEmployee $command,
        AuditedCommandExecutor $executor,
        ScopedAuthorizationChecker $scopeChecker,
    ): JsonResponse {
        $this->guardOwnership($person, $employmentRelationship);

        $data = $request->validate([
            'organizational_unit_id' => ['required', 'uuid'],
            'effective_from' => ['required', 'date'],
            'decision_type_id' => ['required', 'uuid'],
        ]);

        $destination = OrganizationalUnit::query()->find($data['organizational_unit_id']);

        if ($destination === null) {
            throw new NotFoundHttpException('Organizational unit not found.');
        }

        $decisionType = DecisionType::query()->find($data['decision_type_id']);

        if ($decisionType === null) {
            throw new NotFoundHttpException('Decision type not found.');
        }

        /** @var Principal $principal */
        $principal = Auth::guard('web')->user();

        // Same TOCTOU-closing shape as S12's FullSecondmentPeriodController (adversarial-review
        // finding there, applied here from the start rather than corrected after the fact): the
        // relationship row is locked FIRST, and every scope-target lookup (source placement, active
        // secondment) happens inside that lock, before any scope decision is made — genuinely
        // serializing against a concurrent RecordOrganizationalPlacementPeriod/StartFullSecondment/
        // EndFullSecondment/TransferEmployee call on the same relationship, not merely narrowing the
        // window. TransferEmployee re-acquires the identical row lock inside AuditedCommandExecutor's
        // own (nested/savepoint) transaction below — PostgreSQL row locks are reentrant within the
        // same transaction, so this is not a double-lock hazard.
        return DB::transaction(function () use (
            $request, $employmentRelationship, $destination, $decisionType, $principal, $scopeChecker, $data, $command, $executor,
        ) {
            EmploymentRelationship::query()->where('id', $employmentRelationship->getKey())->lockForUpdate()->firstOrFail();

            // Destination is always checked (spec §12.1) — a transfer always moves the original
            // placement there.
            if (! $scopeChecker->authorize($principal, Perm::EMPLOYMENT_RELATIONSHIPS_TRANSFER, $destination)) {
                return $this->forbidden();
            }

            $source = $this->currentPlacementUnit($employmentRelationship);

            if ($source !== null && ! $scopeChecker->authorize($principal, Perm::EMPLOYMENT_RELATIONSHIPS_TRANSFER, $source)) {
                return $this->forbidden();
            }

            $secondmentUnit = $this->activeSecondmentUnit($employmentRelationship, $data['effective_from']);

            if ($secondmentUnit !== null && ! $scopeChecker->authorize($principal, Perm::EMPLOYMENT_RELATIONSHIPS_TRANSFER, $secondmentUnit)) {
                return $this->forbidden();
            }

            $assignmentUnit = $this->activeAssignmentUnit($employmentRelationship, $data['effective_from']);

            if ($assignmentUnit !== null && ! $scopeChecker->authorize($principal, Perm::EMPLOYMENT_RELATIONSHIPS_TRANSFER, $assignmentUnit)) {
                return $this->forbidden();
            }

            // S30 (docs/partial-secondment-foundation-specification.md §S30.13): every partial
            // secondment effective at the transfer date is closed by it, so each unit is checked.
            $partials = app(SupersedeTemporaryWorkplaceMovement::class)
                ->effectiveAllAt(PartialSecondmentPeriod::class, $employmentRelationship->getKey(), Carbon::parse($data['effective_from'])->toDateString());

            foreach ($partials as $partial) {
                if (! $scopeChecker->authorize($principal, Perm::EMPLOYMENT_RELATIONSHIPS_TRANSFER, $partial->organizationalUnit)) {
                    return $this->forbidden();
                }
            }

            $context = ResolveCommandContext::from($request);

            $spec = new AuditSpec(
                action: 'hr.transfer.execute',
                targetType: 'hr_employment_relationship',
                targetId: fn () => $employmentRelationship->getKey(),
                changes: fn (TransferResult $result) => [
                    'employment_relationship_id' => $employmentRelationship->getKey(),
                    'decision_type_id' => $decisionType->getKey(),
                    'organizational_placement_period_id' => $result->placement()->getKey(),
                    'organizational_unit_id' => $result->placement()->organizational_unit_id,
                    'effective_from' => $result->placement()->effective_from?->toDateString(),
                    // null when no full secondment was active — the audit entry still discloses
                    // that this consequence was considered, not merely omitted (spec §19).
                    'closed_full_secondment_period_id' => $result->closedSecondment()?->getKey(),
                    // S16: same discipline for the workplace-assignment consequence — null when
                    // no assignment was active, never simply omitted.
                    'closed_workplace_assignment_period_id' => $result->closedAssignment()?->getKey(),
                    // S30: every partial secondment closed by the transfer — [] when none, never omitted.
                    'closed_partial_secondment_period_ids' => array_map(fn ($period) => $period->getKey(), $result->closedPartialSecondments()),
                ],
                metadata: fn () => [],
            );

            $result = $executor->run(
                $context,
                $spec,
                fn (): TransferResult => $command->handle(
                    $employmentRelationship,
                    $destination,
                    $data['effective_from'],
                    $decisionType,
                ),
            );

            return (new TransferResource($result))->response()->setStatusCode(201);
        });
    }

    private function guardOwnership(Person $person, EmploymentRelationship $employmentRelationship): void
    {
        if ($employmentRelationship->person_id !== $person->getKey()) {
            throw new NotFoundHttpException('Employment relationship not found for this person.');
        }
    }

    private function currentPlacementUnit(EmploymentRelationship $relationship): ?OrganizationalUnit
    {
        $placement = OrganizationalPlacementPeriod::query()
            ->where('employment_relationship_id', $relationship->getKey())
            ->whereNull('effective_to')
            ->first();

        return $placement?->organizationalUnit;
    }

    /**
     * S28 (ADR-S28-001 §8): the unit of the full secondment EFFECTIVE at the transfer date — the
     * one TransferEmployee truncates — open, or closed with a later effective_to. Never "open row".
     */
    private function activeSecondmentUnit(EmploymentRelationship $relationship, string $effectiveFrom): ?OrganizationalUnit
    {
        return app(SupersedeTemporaryWorkplaceMovement::class)
            ->effectiveAt(FullSecondmentPeriod::class, $relationship->getKey(), Carbon::parse($effectiveFrom)->toDateString())
            ?->organizationalUnit;
    }

    /** S28: same interval-aware rule for the workplace assignment effective at the transfer date. */
    private function activeAssignmentUnit(EmploymentRelationship $relationship, string $effectiveFrom): ?OrganizationalUnit
    {
        return app(SupersedeTemporaryWorkplaceMovement::class)
            ->effectiveAt(WorkplaceAssignmentPeriod::class, $relationship->getKey(), Carbon::parse($effectiveFrom)->toDateString())
            ?->organizationalUnit;
    }

    private function forbidden(): JsonResponse
    {
        return response()->json(['message' => 'This action is unauthorized.'], 403);
    }
}
