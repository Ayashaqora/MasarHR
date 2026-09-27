<?php

namespace App\Modules\HumanResources\Presentation\Http\Controllers;

use App\Modules\Audit\Application\AuditedCommandExecutor;
use App\Modules\Audit\Domain\AuditSpec;
use App\Modules\HumanResources\Application\Commands\RecordOrganizationalPlacementPeriod;
use App\Modules\HumanResources\Application\Queries\ListOrganizationalPlacementPeriodsForRelationship;
use App\Modules\HumanResources\Infrastructure\Authorization\HumanResourcesPermissionCatalog as Perm;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\EmploymentRelationship;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\OrganizationalPlacementPeriod;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\Person;
use App\Modules\HumanResources\Presentation\Http\Resources\OrganizationalPlacementPeriodResource;
use App\Modules\Organization\Infrastructure\Persistence\Eloquent\OrganizationalUnit;
use App\Modules\Platform\Presentation\Http\Middleware\ResolveCommandContext;
use App\Modules\Security\Infrastructure\Authorization\ScopedAuthorizationChecker;
use App\Modules\Security\Infrastructure\Persistence\Eloquent\Principal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * S11 Organizational Placement administration surface (spec §18), nested under an existing
 * {person}/{employmentRelationship} — mirrors EmploymentStatusPeriodController's own nested shape
 * exactly. Every route here additionally composes S08's existing, unmodified
 * ScopedAuthorizationChecker on top of the route-level `permission:` middleware (spec §10): the
 * middleware is the coarse WHAT gate, this controller supplies the fine WHERE gate against the
 * relevant OrganizationalUnit target — the first real caller of that checker since S08 built it.
 */
class OrganizationalPlacementPeriodController
{
    public function index(
        Person $person,
        EmploymentRelationship $employmentRelationship,
        ListOrganizationalPlacementPeriodsForRelationship $query,
        ScopedAuthorizationChecker $scopeChecker,
    ): JsonResponse {
        if ($employmentRelationship->person_id !== $person->getKey()) {
            throw new NotFoundHttpException('Employment relationship not found for this person.');
        }

        $periods = $query($employmentRelationship);

        // Spec §10: if a current placement exists, scope must cover its unit. If none exists yet,
        // there is no unit to check against, and the result is necessarily empty — authorized on
        // the route's own permission check alone (already satisfied by this point).
        $current = $periods->firstWhere('effective_to', null);

        if ($current !== null) {
            /** @var Principal $principal */
            $principal = Auth::guard('web')->user();

            if (! $scopeChecker->authorize($principal, Perm::ORGANIZATIONAL_PLACEMENT_PERIODS_VIEW, $current->organizationalUnit)) {
                return response()->json(['message' => 'This action is unauthorized.'], 403);
            }
        }

        return OrganizationalPlacementPeriodResource::collection($periods)->response();
    }

    public function store(
        Request $request,
        Person $person,
        EmploymentRelationship $employmentRelationship,
        RecordOrganizationalPlacementPeriod $command,
        AuditedCommandExecutor $executor,
        ScopedAuthorizationChecker $scopeChecker,
    ): JsonResponse {
        if ($employmentRelationship->person_id !== $person->getKey()) {
            throw new NotFoundHttpException('Employment relationship not found for this person.');
        }

        $data = $request->validate([
            'organizational_unit_id' => ['required', 'uuid'],
            'effective_from' => ['required', 'date'],
        ]);

        $unit = OrganizationalUnit::query()->find($data['organizational_unit_id']);

        if ($unit === null) {
            throw new NotFoundHttpException('Organizational unit not found.');
        }

        /** @var Principal $principal */
        $principal = Auth::guard('web')->user();

        // Spec §10: the destination unit is the WHERE target for a write. ScopedAuthorizationChecker
        // uniformly denies a missing permission, an out-of-scope unit, and an inactive unit behind
        // the same generic 403 — no distinguishing detail is leaked between the three causes.
        if (! $scopeChecker->authorize($principal, Perm::ORGANIZATIONAL_PLACEMENT_PERIODS_RECORD, $unit)) {
            return response()->json(['message' => 'This action is unauthorized.'], 403);
        }

        $context = ResolveCommandContext::from($request);

        $spec = new AuditSpec(
            action: 'hr.organizational_placement_period.record',
            targetType: 'hr_organizational_placement_period',
            targetId: fn (OrganizationalPlacementPeriod $period) => $period->getKey(),
            changes: fn (OrganizationalPlacementPeriod $period) => [
                'employment_relationship_id' => $period->employment_relationship_id,
                'organizational_unit_id' => $period->organizational_unit_id,
                'effective_from' => $period->effective_from?->toDateString(),
            ],
            metadata: fn () => [],
        );

        $period = $executor->run(
            $context,
            $spec,
            fn () => $command->handle(
                $employmentRelationship,
                $unit,
                $data['effective_from'],
            ),
        );

        return (new OrganizationalPlacementPeriodResource($period))->response()->setStatusCode(201);
    }
}
