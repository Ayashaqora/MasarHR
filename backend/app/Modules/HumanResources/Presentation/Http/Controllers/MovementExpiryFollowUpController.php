<?php

namespace App\Modules\HumanResources\Presentation\Http\Controllers;

use App\Modules\HumanResources\Domain\TemporaryMovementType;
use App\Modules\HumanResources\Infrastructure\Authorization\HumanResourcesPermissionCatalog as Perm;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\MovementExpiryFollowUp;
use App\Modules\HumanResources\Presentation\Http\Resources\MovementExpiryFollowUpResource;
use App\Modules\Organization\Infrastructure\Persistence\Eloquent\OrganizationalUnit;
use App\Modules\Platform\Application\Clock\BusinessDateClock;
use App\Modules\Security\Infrastructure\Authorization\ScopedAuthorizationChecker;
use App\Modules\Security\Infrastructure\Persistence\Eloquent\Principal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/**
 * S31 Movement Expiry follow-up READ surface (docs/movement-expiry-followup-foundation-
 * specification.md §S31.17). One list endpoint, no writes: follow-ups are created and transitioned
 * only by the system scanner, so there is no store/update/delete and no scheduler mechanics are
 * exposed. RBAC comes from the route's permission: middleware; organizational scope is the S08
 * ScopedAuthorizationChecker applied to each follow-up's movement DESTINATION unit (the temporary
 * workplace — the same unit S12/S16 reads are scoped to while the movement is in force). Rows the
 * caller's scope does not cover are simply absent — never a per-row 403/404 — so neither their
 * existence nor the total count leaks across organizational scope.
 */
class MovementExpiryFollowUpController
{
    public function index(Request $request, ScopedAuthorizationChecker $scopeChecker, BusinessDateClock $clock): JsonResponse
    {
        $data = $request->validate([
            'state' => ['nullable', Rule::in(['ACTIONABLE', 'LAPSED', 'SUPPRESSED', 'ALL'])],
            'movement_type' => ['nullable', Rule::in(array_map(fn (TemporaryMovementType $t) => $t->value, TemporaryMovementType::cases()))],
            'employment_relationship_id' => ['nullable', 'uuid'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $today = $clock->today()->toDateString();
        $request->attributes->set('business_date', $today);

        $query = MovementExpiryFollowUp::query();

        match ($data['state'] ?? 'ACTIONABLE') {
            'ACTIONABLE' => $query->where('status', MovementExpiryFollowUp::ACTIONABLE)->where('expected_effective_to', '>', $today),
            'LAPSED' => $query->where('status', MovementExpiryFollowUp::ACTIONABLE)->where('expected_effective_to', '<=', $today),
            'SUPPRESSED' => $query->where('status', MovementExpiryFollowUp::SUPPRESSED),
            default => null,
        };

        if (isset($data['movement_type'])) {
            $query->where('movement_type', $data['movement_type']);
        }

        if (isset($data['employment_relationship_id'])) {
            $query->where('employment_relationship_id', $data['employment_relationship_id']);
        }

        /** @var Principal $principal */
        $principal = Auth::guard('web')->user();

        $unitIds = (clone $query)->distinct()->pluck('organizational_unit_id')->all();
        $visibleUnitIds = OrganizationalUnit::query()->whereIn('id', $unitIds)->get()
            ->filter(fn (OrganizationalUnit $unit) => $scopeChecker->authorize($principal, Perm::MOVEMENT_EXPIRY_FOLLOWUPS_VIEW, $unit))
            ->pluck('id')->all();

        $page = $query
            ->whereIn('organizational_unit_id', $visibleUnitIds)
            ->orderBy('due_date')
            ->orderBy('id')
            ->paginate((int) ($data['per_page'] ?? 25));

        return MovementExpiryFollowUpResource::collection($page)->response();
    }
}
