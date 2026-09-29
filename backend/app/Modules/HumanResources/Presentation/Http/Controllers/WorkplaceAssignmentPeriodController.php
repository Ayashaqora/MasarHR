<?php

namespace App\Modules\HumanResources\Presentation\Http\Controllers;

use App\Modules\Audit\Application\AuditedCommandExecutor;
use App\Modules\Audit\Domain\AuditSpec;
use App\Modules\HumanResources\Application\Commands\EndWorkplaceAssignment;
use App\Modules\HumanResources\Application\Commands\StartWorkplaceAssignment;
use App\Modules\HumanResources\Application\Commands\SupersedeTemporaryWorkplaceMovement;
use App\Modules\HumanResources\Application\Queries\ListWorkplaceAssignmentPeriodsForRelationship;
use App\Modules\HumanResources\Application\Queries\ResolveActualWorkplaceForRelationship;
use App\Modules\HumanResources\Infrastructure\Authorization\HumanResourcesPermissionCatalog as Perm;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\EmploymentRelationship;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\FullSecondmentPeriod;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\OrganizationalPlacementPeriod;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\PartialSecondmentPeriod;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\Person;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\WorkplaceAssignmentPeriod;
use App\Modules\HumanResources\Presentation\Http\Resources\WorkplaceAssignmentPeriodResource;
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
 * S16 Workplace Assignment administration surface
 * (docs/workplace-assignment-foundation-specification.md §S16.14/§S16.16), nested under an
 * existing {person}/{employmentRelationship} — mirrors FullSecondmentPeriodController's (S12) own
 * nested shape and its S12.1-derived two-target ScopedAuthorizationChecker composition exactly.
 * The existing actual-workplace read route (FullSecondmentPeriodController::actualWorkplace) is
 * not duplicated here — ResolveActualWorkplaceForRelationship was extended in place (spec
 * §S16.8) to also resolve an active assignment, so that one existing route already covers it.
 */
class WorkplaceAssignmentPeriodController
{
    public function index(
        Person $person,
        EmploymentRelationship $employmentRelationship,
        ListWorkplaceAssignmentPeriodsForRelationship $query,
        ResolveActualWorkplaceForRelationship $resolveActualWorkplace,
        ScopedAuthorizationChecker $scopeChecker,
    ): JsonResponse {
        $this->guardOwnership($person, $employmentRelationship);

        if (! $this->authorizeRead($employmentRelationship, $resolveActualWorkplace, $scopeChecker, Perm::WORKPLACE_ASSIGNMENT_PERIODS_VIEW)) {
            return $this->forbidden();
        }

        $periods = $query($employmentRelationship);

        return WorkplaceAssignmentPeriodResource::collection($periods)->response();
    }

    public function store(
        Request $request,
        Person $person,
        EmploymentRelationship $employmentRelationship,
        StartWorkplaceAssignment $command,
        AuditedCommandExecutor $executor,
        ScopedAuthorizationChecker $scopeChecker,
        SupersedeTemporaryWorkplaceMovement $supersession,
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

        // Same TOCTOU-closing shape as S12's FullSecondmentPeriodController/S14's
        // TransferController, applied here from the first draft (ADR-S16-001 §21's explicit
        // instruction to apply the S15 lesson proactively): the relationship row is locked FIRST,
        // and every scope-target lookup happens inside that lock, before any scope decision is
        // made. StartWorkplaceAssignment re-acquires the identical row lock inside
        // AuditedCommandExecutor's own (nested/savepoint) transaction below — PostgreSQL row locks
        // are reentrant within the same transaction, so this is not a double-lock hazard.
        return DB::transaction(function () use (
            $request, $employmentRelationship, $destination, $decisionType, $principal, $scopeChecker, $data, $command, $executor, $supersession,
        ) {
            EmploymentRelationship::query()->where('id', $employmentRelationship->getKey())->lockForUpdate()->firstOrFail();

            // Destination is always checked (mirrors spec §S16.14) — an assignment always makes
            // this the (temporary) actual workplace.
            if (! $scopeChecker->authorize($principal, Perm::WORKPLACE_ASSIGNMENT_PERIODS_START, $destination)) {
                return $this->forbidden();
            }

            $source = $this->currentPlacementUnit($employmentRelationship);

            if ($source !== null && ! $scopeChecker->authorize($principal, Perm::WORKPLACE_ASSIGNMENT_PERIODS_START, $source)) {
                return $this->forbidden();
            }

            // ADR-S28-001: a full secondment effective at the start date is superseded
            // (truncated); its unit is scope-checked with the same permission (the S14 precedent).
            // The unchanged S16 same-stream "close previous assignment" consequence keeps its
            // original authorization shape and is only recorded in the audit metadata.
            $date = Carbon::parse($data['effective_from'])->toDateString();
            $supersededSecondment = $supersession->effectiveAt(FullSecondmentPeriod::class, $employmentRelationship->getKey(), $date);
            $previousAssignment = $supersession->effectiveAt(WorkplaceAssignmentPeriod::class, $employmentRelationship->getKey(), $date);

            if ($supersededSecondment !== null && ! $scopeChecker->authorize($principal, Perm::WORKPLACE_ASSIGNMENT_PERIODS_START, $supersededSecondment->organizationalUnit)) {
                return $this->forbidden();
            }

            // S30 ADR-S30-007 rule 1: every partial secondment effective at the start date is
            // superseded too, each unit scope-checked with the same permission.
            $supersededPartials = $supersession->effectiveAllAt(PartialSecondmentPeriod::class, $employmentRelationship->getKey(), $date);

            foreach ($supersededPartials as $partial) {
                if (! $scopeChecker->authorize($principal, Perm::WORKPLACE_ASSIGNMENT_PERIODS_START, $partial->organizationalUnit)) {
                    return $this->forbidden();
                }
            }

            $context = ResolveCommandContext::from($request);

            $spec = new AuditSpec(
                action: 'hr.workplace_assignment_period.start',
                targetType: 'hr_workplace_assignment_period',
                targetId: fn (WorkplaceAssignmentPeriod $period) => $period->getKey(),
                changes: fn (WorkplaceAssignmentPeriod $period) => [
                    'employment_relationship_id' => $period->employment_relationship_id,
                    'organizational_unit_id' => $period->organizational_unit_id,
                    'effective_from' => $period->effective_from?->toDateString(),
                    'decision_type_id' => $decisionType->getKey(),
                ],
                metadata: fn () => FullSecondmentPeriodController::supersessionMetadata([
                    ['full_secondment', FullSecondmentPeriod::class, $supersededSecondment],
                    ['workplace_assignment', WorkplaceAssignmentPeriod::class, $previousAssignment],
                    ...array_map(fn ($partial) => ['partial_secondment', PartialSecondmentPeriod::class, $partial], $supersededPartials),
                ], $date),
            );

            $period = $executor->run(
                $context,
                $spec,
                fn () => $command->handle($employmentRelationship, $destination, $data['effective_from'], $decisionType),
            );

            return (new WorkplaceAssignmentPeriodResource($period))->response()->setStatusCode(201);
        });
    }

    public function end(
        Request $request,
        Person $person,
        EmploymentRelationship $employmentRelationship,
        EndWorkplaceAssignment $command,
        AuditedCommandExecutor $executor,
        ScopedAuthorizationChecker $scopeChecker,
    ): JsonResponse {
        $this->guardOwnership($person, $employmentRelationship);

        $data = $request->validate([
            'effective_to' => ['required', 'date'],
        ]);

        /** @var Principal $principal */
        $principal = Auth::guard('web')->user();

        // Same TOCTOU-closing shape as store() above: the relationship row is locked first, and
        // both the open-period lookup and the "source" placement-unit lookup happen inside that
        // lock, before the scope decision is made.
        return DB::transaction(function () use ($request, $employmentRelationship, $principal, $scopeChecker, $data, $command, $executor) {
            EmploymentRelationship::query()->where('id', $employmentRelationship->getKey())->lockForUpdate()->firstOrFail();

            $openPeriod = WorkplaceAssignmentPeriod::query()
                ->where('employment_relationship_id', $employmentRelationship->getKey())
                ->whereNull('effective_to')
                ->first();

            // When there is nothing open to end, there is no assignment-destination unit to check
            // scope against — the base permission (already satisfied by the route's permission:
            // middleware) governs, and the command's own NoActiveWorkplaceAssignmentException
            // (409) supplies the substantive rejection once invoked below.
            if ($openPeriod !== null) {
                if (! $scopeChecker->authorize($principal, Perm::WORKPLACE_ASSIGNMENT_PERIODS_END, $openPeriod->organizationalUnit)) {
                    return $this->forbidden();
                }

                $source = $this->currentPlacementUnit($employmentRelationship);

                if ($source !== null && ! $scopeChecker->authorize($principal, Perm::WORKPLACE_ASSIGNMENT_PERIODS_END, $source)) {
                    return $this->forbidden();
                }
            }

            $context = ResolveCommandContext::from($request);

            $spec = new AuditSpec(
                action: 'hr.workplace_assignment_period.end',
                targetType: 'hr_workplace_assignment_period',
                targetId: fn (WorkplaceAssignmentPeriod $period) => $period->getKey(),
                changes: fn (WorkplaceAssignmentPeriod $period) => [
                    'id' => $period->getKey(),
                    'effective_to' => $period->effective_to?->toDateString(),
                ],
                metadata: fn () => [],
            );

            $period = $executor->run(
                $context,
                $spec,
                fn () => $command->handle($employmentRelationship, $data['effective_to']),
            );

            return (new WorkplaceAssignmentPeriodResource($period))->response();
        });
    }

    private function guardOwnership(Person $person, EmploymentRelationship $employmentRelationship): void
    {
        if ($employmentRelationship->person_id !== $person->getKey()) {
            throw new NotFoundHttpException('Employment relationship not found for this person.');
        }
    }

    /** A read's scope-check target is whichever single unit resolves as "actual workplace". */
    private function authorizeRead(
        EmploymentRelationship $employmentRelationship,
        ResolveActualWorkplaceForRelationship $resolveActualWorkplace,
        ScopedAuthorizationChecker $scopeChecker,
        string $permissionCode,
    ): bool {
        $workplace = $resolveActualWorkplace($employmentRelationship);

        if (! $workplace->isResolved()) {
            return true;
        }

        /** @var Principal $principal */
        $principal = Auth::guard('web')->user();

        $unit = OrganizationalUnit::query()->findOrFail($workplace->organizationalUnitId());

        return $scopeChecker->authorize($principal, $permissionCode, $unit);
    }

    private function currentPlacementUnit(EmploymentRelationship $relationship): ?OrganizationalUnit
    {
        $placement = OrganizationalPlacementPeriod::query()
            ->where('employment_relationship_id', $relationship->getKey())
            ->whereNull('effective_to')
            ->first();

        return $placement?->organizationalUnit;
    }

    private function forbidden(): JsonResponse
    {
        return response()->json(['message' => 'This action is unauthorized.'], 403);
    }
}
