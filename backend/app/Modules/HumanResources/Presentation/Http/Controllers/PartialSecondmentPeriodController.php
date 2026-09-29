<?php

namespace App\Modules\HumanResources\Presentation\Http\Controllers;

use App\Modules\Audit\Application\AuditedCommandExecutor;
use App\Modules\Audit\Domain\AuditSpec;
use App\Modules\HumanResources\Application\Commands\RecordPartialSecondmentPeriod;
use App\Modules\HumanResources\Application\Commands\SupersedeTemporaryWorkplaceMovement;
use App\Modules\HumanResources\Application\Queries\ListPartialSecondmentPeriodsForRelationship;
use App\Modules\HumanResources\Application\Queries\ResolveActualWorkplaceForRelationship;
use App\Modules\HumanResources\Infrastructure\Authorization\HumanResourcesPermissionCatalog as Perm;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\EmploymentRelationship;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\OrganizationalPlacementPeriod;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\PartialSecondmentPeriod;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\Person;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\WorkplaceAssignmentPeriod;
use App\Modules\HumanResources\Presentation\Http\Resources\PartialSecondmentPeriodResource;
use App\Modules\Organization\Infrastructure\Persistence\Eloquent\OrganizationalUnit;
use App\Modules\Platform\Presentation\Http\Middleware\ResolveCommandContext;
use App\Modules\Security\Infrastructure\Authorization\ScopedAuthorizationChecker;
use App\Modules\Security\Infrastructure\Persistence\Eloquent\Principal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * S30 Partial Secondment surface (docs/partial-secondment-foundation-specification.md §S30.19),
 * nested under an existing {person}/{employmentRelationship}. Mirrors S12/S16's movement
 * controllers: the route's permission: middleware for RBAC, then the unmodified S08
 * ScopedAuthorizationChecker for organizational scope, with the relationship row locked FIRST so
 * every scope target and the audit snapshot are read inside that lock (the S12 TOCTOU lesson).
 * Explicit record action only: no generic PATCH/PUT, no DELETE, no end or correction route.
 */
class PartialSecondmentPeriodController
{
    public function index(
        Person $person,
        EmploymentRelationship $employmentRelationship,
        ListPartialSecondmentPeriodsForRelationship $query,
        ResolveActualWorkplaceForRelationship $resolveActualWorkplace,
        ScopedAuthorizationChecker $scopeChecker,
    ): JsonResponse {
        $this->guardOwnership($person, $employmentRelationship);

        // Same read-scope target as S12/S16's movement history reads: the relationship's
        // current resolved workplace.
        $workplace = $resolveActualWorkplace($employmentRelationship);

        if ($workplace->isResolved()) {
            /** @var Principal $principal */
            $principal = Auth::guard('web')->user();
            $unit = OrganizationalUnit::query()->findOrFail($workplace->organizationalUnitId());

            if (! $scopeChecker->authorize($principal, Perm::PARTIAL_SECONDMENT_PERIODS_VIEW, $unit)) {
                return $this->forbidden();
            }
        }

        return PartialSecondmentPeriodResource::collection($query($employmentRelationship))->response();
    }

    public function store(
        Request $request,
        Person $person,
        EmploymentRelationship $employmentRelationship,
        RecordPartialSecondmentPeriod $command,
        AuditedCommandExecutor $executor,
        ScopedAuthorizationChecker $scopeChecker,
        SupersedeTemporaryWorkplaceMovement $supersession,
    ): JsonResponse {
        $this->guardOwnership($person, $employmentRelationship);

        // Shape only here; allocation, schedule, date and conflict rules live in
        // RecordPartialSecondmentPeriod so the command enforces them for every caller.
        $data = $request->validate([
            'organizational_unit_id' => ['required', 'uuid'],
            'effective_from' => ['required', 'date'],
            'effective_to' => ['nullable', 'date'],
            'weekdays' => ['present', 'array'],
            'weekdays.*' => ['string'],
        ]);

        $destination = OrganizationalUnit::query()->find($data['organizational_unit_id']);

        if ($destination === null) {
            throw new NotFoundHttpException('Organizational unit not found.');
        }

        $weekdayCodes = array_values($data['weekdays']);
        $effectiveTo = $data['effective_to'] ?? null;

        /** @var Principal $principal */
        $principal = Auth::guard('web')->user();

        return DB::transaction(function () use (
            $request, $employmentRelationship, $destination, $principal, $scopeChecker, $data, $effectiveTo, $weekdayCodes, $command, $executor, $supersession,
        ) {
            EmploymentRelationship::query()->where('id', $employmentRelationship->getKey())->lockForUpdate()->firstOrFail();

            // The S12 dual-scope rule: the destination is always checked, the current placement
            // unit ("source") additionally when one is recorded.
            if (! $scopeChecker->authorize($principal, Perm::PARTIAL_SECONDMENT_PERIODS_RECORD, $destination)) {
                return $this->forbidden();
            }

            $source = OrganizationalPlacementPeriod::query()
                ->where('employment_relationship_id', $employmentRelationship->getKey())
                ->whereNull('effective_to')
                ->first()?->organizationalUnit;

            if ($source !== null && ! $scopeChecker->authorize($principal, Perm::PARTIAL_SECONDMENT_PERIODS_RECORD, $source)) {
                return $this->forbidden();
            }

            // ADR-S30-007 rule 2: a workplace assignment effective at the start date is superseded
            // (truncated); like S28, its unit is scope-checked with the same permission so a caller
            // can never end a movement in a unit outside their scope.
            $date = Carbon::parse($data['effective_from'])->toDateString();
            $superseded = $supersession->effectiveAt(WorkplaceAssignmentPeriod::class, $employmentRelationship->getKey(), $date);

            if ($superseded !== null && ! $scopeChecker->authorize($principal, Perm::PARTIAL_SECONDMENT_PERIODS_RECORD, $superseded->organizationalUnit)) {
                return $this->forbidden();
            }

            $spec = new AuditSpec(
                action: 'hr.partial_secondment_period.record',
                targetType: 'hr_partial_secondment_period',
                targetId: fn (PartialSecondmentPeriod $period) => $period->getKey(),
                changes: fn (PartialSecondmentPeriod $period) => [
                    'employment_relationship_id' => $period->employment_relationship_id,
                    'organizational_unit_id' => $period->organizational_unit_id,
                    'effective_from' => $period->effective_from?->toDateString(),
                    'effective_to' => $period->effective_to?->toDateString(),
                    'weekdays' => $period->weekdayCodes(),
                ],
                metadata: fn () => FullSecondmentPeriodController::supersessionMetadata(
                    [['workplace_assignment', WorkplaceAssignmentPeriod::class, $superseded]],
                    $date,
                ),
            );

            $period = $executor->run(
                ResolveCommandContext::from($request),
                $spec,
                fn () => $command->handle($employmentRelationship, $destination, $data['effective_from'], $effectiveTo, $weekdayCodes),
            );

            return (new PartialSecondmentPeriodResource($period))->response()->setStatusCode(201);
        });
    }

    private function guardOwnership(Person $person, EmploymentRelationship $employmentRelationship): void
    {
        if ($employmentRelationship->person_id !== $person->getKey()) {
            throw new NotFoundHttpException('Employment relationship not found for this person.');
        }
    }

    private function forbidden(): JsonResponse
    {
        return response()->json(['message' => 'This action is unauthorized.'], 403);
    }
}
