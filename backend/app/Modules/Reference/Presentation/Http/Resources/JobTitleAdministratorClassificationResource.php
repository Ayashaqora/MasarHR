<?php

namespace App\Modules\Reference\Presentation\Http\Resources;

use App\Modules\Reference\Infrastructure\Persistence\Eloquent\JobTitleAdministratorClassification;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin JobTitleAdministratorClassification */
class JobTitleAdministratorClassificationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'job_title_id' => $this->job_title_id,
            'is_administrator' => (bool) $this->is_administrator,
            'effective_from' => $this->effective_from?->toDateString(),
            'effective_to' => $this->effective_to?->toDateString(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
