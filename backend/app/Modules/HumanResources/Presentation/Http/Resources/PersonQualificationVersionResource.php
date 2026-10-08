<?php

namespace App\Modules\HumanResources\Presentation\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * S48 versions-list / correction-success item shape, frozen verbatim by D26/D37
 * (docs/person-qualification-history-foundation-specification.md §S48.14): exactly
 * `version_number`, `academic_degree_id`, `qualification_type_id`, `obtained_on`, `reason`,
 * `is_current`, `provenance` (D36), `created_by_principal_id`, `created_at` — no invented fields.
 */
class PersonQualificationVersionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'version_number' => $this->version_number,
            'academic_degree_id' => $this->academic_degree_id,
            'qualification_type_id' => $this->qualification_type_id,
            'obtained_on' => $this->obtained_on?->toDateString(),
            'reason' => $this->reason,
            'is_current' => (bool) $this->is_current,
            'provenance' => $this->provenance(),
            'created_by_principal_id' => $this->created_by_principal_id,
            'created_at' => $this->created_at,
        ];
    }
}
