<?php

namespace App\Modules\HumanResources\Presentation\Http\Controllers;

use App\Modules\Audit\Application\AuditedCommandExecutor;
use App\Modules\Audit\Domain\AuditSpec;
use App\Modules\HumanResources\Application\Commands\EndFullSecondment;
use App\Modules\HumanResources\Application\Commands\StartFullSecondment;
use App\Modules\HumanResources\Application\Queries\ListFullSecondmentPeriodsForRelationship;
use App\Modules\HumanResources\Application\Queries\ResolveActualWorkplaceForRelationship;
use App\Modules\HumanResources\Domain\ActualWorkplace;
use App\Modules\HumanResources\Infrastructure\Authorization\HumanResourcesPermissionCatalog as Perm;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\EmploymentRelationship;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\FullSecondmentPeriod;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\OrganizationalPlacementPeriod;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\Person;
use App\Modules\HumanResources\Presentation\Http\Resources\ActualWorkplaceResource;
use App\Modules\HumanResources\Presentation\Http\Resources\FullSecondmentPeriodResource;
use App\Modules\Organization\Infrastructure\Persistence\Eloquent\OrganizationalUnit;
use App\Modules\Platform\Presentation\Http\Middleware\ResolveCommandContext;
use App\Modules\Security\Infrastructure\Authorization\ScopedAuthorizationChecker;
use App\Modules\Security\Infrastructure\Persistence\Eloquent\Principal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * S12 Full Secondment administration surface (spec §20), nested under an existing
 * {person}/{employmentRelationship} — mirrors OrganizationalPlacementPeriodController's (S11) own
 * nested shape. Every mutating route here composes S08's existing, unmodified
 * ScopedAuthorizationChecker against up to TWO targets (spec §12.1: the authorization's own
 * flagged "critical S12 adversarial point," resolved explicitly rather than deferred) — the
 * checker itself is never modified, only called more than once.
 */
class FullSecondmentPeriodController
{
    public function index(
        Person $person,
        EmploymentRelationship $employmentRelationship,
        ListFullSecondmentPeriodsForRelationship $query,
        ResolveActualWorkplaceForRelationship $resolveActualWorkplace,
        ScopedAuthorizationChecker $scopeChecker,
    ): JsonResponse {
        $this->guardOwnership($person, $employmentRelationship);

        if (! $this->authorizeRead($employmentRelationship, $resolveActualWorkplace, $scopeChecker, Perm::FULL_SECONDMENT_PERIODS_VIEW)) {
            return $this->forbidden();
        }

        $periods = $query($employmentRelationship);

        return FullSecondmentPeriodResource::collection($periods)->response();
    }

    public function actualWorkplace(
        Person $person,
        EmploymentRelationship $employmentRelationship,
        ResolveActualWorkplaceForRelationship $resolveActualWorkplace,
        ScopedAuthorizationChecker $scopeChecker,
    ): JsonResponse {
        $this->guardOwnership($person, $employmentRelationship);

        $workplace = $resolveActualWorkplace($employmentRelationship);

        if (! $this->authorizeReadResolved($workplace, $scopeChecker, Perm::FULL_SECONDMENT_PERIODS_VIEW)) {
            return $this->forbidden();
        }

        return (new ActualWorkplaceResource($workplace))->response();
    }

    public function store(
        Request $request,
        Person $person,
        EmploymentRelationship $employmentRelationship,
        StartFullSecondment $command,
        AuditedCommandExecutor $executor,
        ScopedAuthorizationChecker $scopeChecker,
    ): JsonResponse {
        $this->guardOwnership($person, $employmentRelationship);

        $data = $request->validate([
            'organizational_unit_id' => ['required', 'uuid'],
            'effective_from' => ['required', 'date'],
        ]);

        $destination = OrganizationalUnit::query()->find($data['organizational_unit_id']);

        if ($destination === null) {
            throw new NotFoundHttpException('Organizational unit not found.');
        }

        /** @var Principal $principal */
        $principal = Auth::guard('web')->user();

        // The whole authorization decision through to the command's own write runs inside one
        // transaction, locking the relationship row FIRST (adversarial review finding, spec
        // §12.1 addendum): reading the "source" unit for the scope check outside any lock left a
        // TOCTOU window — a concurrent RecordOrganizationalPlacementPeriod call (S11) could move
        // the relationship to a different unit between this check and the command's own write.
        // RecordOrganizationalPlacementPeriod itself locks this same relationship row before
        // writing a new placement, so holding that lock here for the duration of the scope
        // decision genuinely serializes against it, not merely narrows the window.
        // StartFullSecondment re-acquires the identical row lock inside AuditedCommandExecutor's
        // own (nested/savepoint) transaction below — PostgreSQL row locks are reentrant within
        // the same transaction, so this is not a double-lock hazard.
        return DB::transaction(function () use ($request, $employmentRelationship, $destination, $principal, $scopeChecker, $data, $command, $executor) {
            EmploymentRelationship::query()->where('id', $employmentRelationship->getKey())->lockForUpdate()->firstOrFail();

            // Spec §12.1: destination is always checked; the current placement's unit ("source")
            // is additionally checked when one is recorded — both targets checked with the SAME,
            // unmodified ScopedAuthorizationChecker, never a new authorization class.
            if (! $scopeChecker->authorize($principal, Perm::FULL_SECONDMENT_PERIODS_START, $destination)) {
                return $this->forbidden();
            }

            $source = $this->currentPlacementUnit($employmentRelationship);

            if ($source !== null && ! $scopeChecker->authorize($principal, Perm::FULL_SECONDMENT_PERIODS_START, $source)) {
                return $this->forbidden();
            }

            $context = ResolveCommandContext::from($request);

            $spec = new AuditSpec(
                action: 'hr.full_secondment_period.start',
                targetType: 'hr_full_secondment_period',
                targetId: fn (FullSecondmentPeriod $period) => $period->getKey(),
                changes: fn (FullSecondmentPeriod $period) => [
                    'employment_relationship_id' => $period->employment_relationship_id,
                    'organizational_unit_id' => $period->organizational_unit_id,
                    'effective_from' => $period->effective_from?->toDateString(),
                ],
                metadata: fn () => [],
            );

            $period = $executor->run(
                $context,
                $spec,
                fn () => $command->handle($employmentRelationship, $destination, $data['effective_from']),
            );

            return (new FullSecondmentPeriodResource($period))->response()->setStatusCode(201);
        });
    }

    public function end(
        Request $request,
        Person $person,
        EmploymentRelationship $employmentRelationship,
        EndFullSecondment $command,
        AuditedCommandExecutor $executor,
        ScopedAuthorizationChecker $scopeChecker,
    ): JsonResponse {
        $this->guardOwnership($person, $employmentRelationship);

        $data = $request->validate([
            'effective_to' => ['required', 'date'],
        ]);

        /** @var Principal $principal */
        $principal = Auth::guard('web')->user();

        // Same TOCTOU-closing shape as store() above (adversarial review finding): the
        // relationship row is locked first, and both the open-period lookup and the "source"
        // placement-unit lookup happen inside that lock, before the scope decision is made —
        // not against a snapshot read before any lock was held.
        return DB::transaction(function () use ($request, $employmentRelationship, $principal, $scopeChecker, $data, $command, $executor) {
            EmploymentRelationship::query()->where('id', $employmentRelationship->getKey())->lockForUpdate()->firstOrFail();

            $openPeriod = FullSecondmentPeriod::query()
                ->where('employment_relationship_id', $employmentRelationship->getKey())
                ->whereNull('effective_to')
                ->first();

            // Spec §12.1: when there is nothing open to end, there is no secondment-destination
            // unit to check scope against — the base permission (already satisfied by the
            // route's permission: middleware) governs, and the command's own
            // NoActiveFullSecondmentException (409) supplies the substantive rejection once
            // invoked below.
            if ($openPeriod !== null) {
                if (! $scopeChecker->authorize($principal, Perm::FULL_SECONDMENT_PERIODS_END, $openPeriod->organizationalUnit)) {
                    return $this->forbidden();
                }

                $source = $this->currentPlacementUnit($employmentRelationship);

                if ($source !== null && ! $scopeChecker->authorize($principal, Perm::FULL_SECONDMENT_PERIODS_END, $source)) {
                    return $this->forbidden();
                }
            }

            $context = ResolveCommandContext::from($request);

            $spec = new AuditSpec(
                action: 'hr.full_secondment_period.end',
                targetType: 'hr_full_secondment_period',
                targetId: fn (FullSecondmentPeriod $period) => $period->getKey(),
                changes: fn (FullSecondmentPeriod $period) => [
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

            return (new FullSecondmentPeriodResource($period))->response();
        });
    }

    private function guardOwnership(Person $person, EmploymentRelationship $employmentRelationship): void
    {
        if ($employmentRelationship->person_id !== $person->getKey()) {
            throw new NotFoundHttpException('Employment relationship not found for this person.');
        }
    }

    /** Spec §12.1: a read's scope-check target is whichever single unit resolves as "actual workplace". */
    private function authorizeRead(
        EmploymentRelationship $employmentRelationship,
        ResolveActualWorkplaceForRelationship $resolveActualWorkplace,
        ScopedAuthorizationChecker $scopeChecker,
        string $permissionCode,
    ): bool {
        return $this->authorizeReadResolved($resolveActualWorkplace($employmentRelationship), $scopeChecker, $permissionCode);
    }

    private function authorizeReadResolved(
        ActualWorkplace $workplace,
        ScopedAuthorizationChecker $scopeChecker,
        string $permissionCode,
    ): bool {
        if (! $workplace->isResolved()) {
            // No unit to check scope against — permission alone (already satisfied by the
            // route's permission: middleware) governs, mirroring S11's identical "no unit yet"
            // read-side default.
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
