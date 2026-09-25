<?php

namespace App\Modules\Reference\Presentation\Http\Resources;

use App\Modules\Reference\Infrastructure\Persistence\Eloquent\EmploymentStatusDetailBehavior;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin EmploymentStatusDetailBehavior */
class EmploymentStatusDetailBehaviorResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status_detail_id' => $this->status_detail_id,
            'effective_from' => $this->effective_from?->toDateString(),
            'effective_to' => $this->effective_to?->toDateString(),
            'participates_in_active_workforce' => (bool) $this->participates_in_active_workforce,
            'is_ongoing_relationship' => (bool) $this->is_ongoing_relationship,
            'is_relationship_ending' => (bool) $this->is_relationship_ending,
            'is_terminal' => (bool) $this->is_terminal,
            'allows_reappointment' => $this->allows_reappointment === null ? null : (bool) $this->allows_reappointment,
            'counts_in_monthly_reporting' => $this->counts_in_monthly_reporting === null ? null : (bool) $this->counts_in_monthly_reporting,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
