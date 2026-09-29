<?php

namespace App\Modules\HumanResources\Presentation\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Output shape for a single work schedule period (S29 spec §S29.13). Weekdays are the stable codes
 * (MONDAY…SUNDAY) in ISO order — never localized labels.
 */
class WorkSchedulePeriodResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'employment_relationship_id' => $this->employment_relationship_id,
            'effective_from' => $this->effective_from?->toDateString(),
            'effective_to' => $this->effective_to?->toDateString(),
            'weekdays' => $this->weekdayCodes(),
        ];
    }
}
