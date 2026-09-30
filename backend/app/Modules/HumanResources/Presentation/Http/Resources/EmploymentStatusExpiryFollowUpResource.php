<?php

namespace App\Modules\HumanResources\Presentation\Http\Resources;

use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\EmploymentStatusExpiryFollowUp;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Output shape for one status expiry follow-up (S38 spec §S38.16): ids, dates and stable codes only — no employee
 * data, no localized labels. `state` is derived for the caller's business date: ACTIONABLE (emitted, end still
 * ahead), LAPSED (emitted, the status reached its end as planned — history) or SUPPRESSED (recognised as stale,
 * with its reason code). `suppression_reason` is exposed only for a SUPPRESSED row (the database CHECK makes it
 * null otherwise).
 */
class EmploymentStatusExpiryFollowUpResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var EmploymentStatusExpiryFollowUp $followUp */
        $followUp = $this->resource;

        return [
            'id' => $followUp->getKey(),
            'followup_kind' => $followUp->followup_kind,
            'employment_status_period_id' => $followUp->employment_status_period_id,
            'employment_relationship_id' => $followUp->employment_relationship_id,
            'expected_effective_to' => $followUp->expected_effective_to->toDateString(),
            'due_date' => $followUp->due_date->toDateString(),
            'status' => $followUp->status,
            'state' => $followUp->readState((string) $request->attributes->get('business_date')),
            'suppression_reason' => $followUp->suppression_reason,
            'created_at' => $followUp->created_at?->toIso8601String(),
            'suppressed_at' => $followUp->suppressed_at?->toIso8601String(),
        ];
    }
}
