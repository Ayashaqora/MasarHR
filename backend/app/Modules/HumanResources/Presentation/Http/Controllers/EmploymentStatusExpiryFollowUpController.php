<?php

namespace App\Modules\HumanResources\Presentation\Http\Controllers;

use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\EmploymentStatusExpiryFollowUp;
use App\Modules\HumanResources\Presentation\Http\Resources\EmploymentStatusExpiryFollowUpResource;
use App\Modules\Platform\Application\Clock\BusinessDateClock;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * S38 Employment Status Expiry follow-up READ surface (docs/employment-status-expiry-followup-specification.md
 * §S38.16). One list endpoint, no writes: follow-ups are created and transitioned only by the system scanner, so
 * there is no store/update/delete and no scheduler mechanics are exposed. RBAC comes from the route's dedicated
 * permission: middleware. Plain RBAC by the frozen S38 decision: a status period has no organizational unit, so no
 * organizational scope is derived from placement or movement.
 */
class EmploymentStatusExpiryFollowUpController
{
    public function index(Request $request, BusinessDateClock $clock): JsonResponse
    {
        $data = $request->validate([
            'state' => ['nullable', Rule::in(['ACTIONABLE', 'LAPSED', 'SUPPRESSED', 'ALL'])],
            'employment_relationship_id' => ['nullable', 'uuid'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $today = $clock->today()->toDateString();
        $request->attributes->set('business_date', $today);

        $query = EmploymentStatusExpiryFollowUp::query();

        match ($data['state'] ?? 'ACTIONABLE') {
            'ACTIONABLE' => $query->where('status', EmploymentStatusExpiryFollowUp::ACTIONABLE)->where('expected_effective_to', '>', $today),
            'LAPSED' => $query->where('status', EmploymentStatusExpiryFollowUp::ACTIONABLE)->where('expected_effective_to', '<=', $today),
            'SUPPRESSED' => $query->where('status', EmploymentStatusExpiryFollowUp::SUPPRESSED),
            default => null,
        };

        if (isset($data['employment_relationship_id'])) {
            $query->where('employment_relationship_id', $data['employment_relationship_id']);
        }

        $page = $query
            ->orderBy('due_date')
            ->orderBy('id')
            ->paginate((int) ($data['per_page'] ?? 25));

        return EmploymentStatusExpiryFollowUpResource::collection($page)->response();
    }
}
