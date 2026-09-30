<?php

namespace App\Modules\HumanResources\Presentation\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** S34: one persisted Return Intention period. */
class ReturnIntentionPeriodResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'employment_relationship_id' => $this->employment_relationship_id,
            'intention' => $this->intention,
            'effective_from' => $this->effective_from?->toDateString(),
            'effective_to' => $this->effective_to?->toDateString(),
        ];
    }
}
