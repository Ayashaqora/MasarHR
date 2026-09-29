<?php

namespace App\Modules\HumanResources\Presentation\Http\Resources;

use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\MovementExpiryFollowUp;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Output shape for one movement expiry follow-up (S31 spec §S31.17): ids, dates and stable codes
 * only — no employee data, no localized labels. `state` is derived for the caller's business date:
 * ACTIONABLE (emitted, end date still ahead), LAPSED (emitted, the movement reached its end as
 * planned — history) or SUPPRESSED (recognised as stale, with its reason code).
 */
class MovementExpiryFollowUpResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var MovementExpiryFollowUp $followUp */
        $followUp = $this->resource;
        $today = $request->attributes->get('business_date');

        return [
            'id' => $followUp->getKey(),
            'followup_kind' => $followUp->followup_kind,
            'movement_type' => $followUp->movement_type,
            'movement_id' => $followUp->movement_id,
            'employment_relationship_id' => $followUp->employment_relationship_id,
            'organizational_unit_id' => $followUp->organizational_unit_id,
            'expected_effective_to' => $followUp->expected_effective_to->toDateString(),
            'due_date' => $followUp->due_date->toDateString(),
            'status' => $followUp->status,
            'state' => $followUp->status === MovementExpiryFollowUp::SUPPRESSED
                ? 'SUPPRESSED'
                : ($followUp->expected_effective_to->toDateString() > $today ? 'ACTIONABLE' : 'LAPSED'),
            'suppression_reason' => $followUp->suppression_reason,
            'created_at' => $followUp->created_at?->toIso8601String(),
            'suppressed_at' => $followUp->suppressed_at?->toIso8601String(),
        ];
    }
}
