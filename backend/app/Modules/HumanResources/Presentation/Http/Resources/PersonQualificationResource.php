<?php

namespace App\Modules\HumanResources\Presentation\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Output shape for a single Person qualification (S23 spec §S23.13), now sourced from the
 * qualification's CURRENT version (S48, docs/person-qualification-history-foundation-specification.md
 * §S48.4/§S48.13, D13/D36): gains `obtained_on`, `created_at` (the qualification's own recording
 * timestamp — D02, never confused with the date obtained), `version_number`, and `provenance`.
 * Expects the underlying resource to be a PersonQualification model with its `currentVersion`
 * relation already loaded.
 */
class PersonQualificationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $version = $this->currentVersion;

        return [
            'id' => $this->id,
            'person_id' => $this->person_id,
            'academic_degree_id' => $version?->academic_degree_id,
            'qualification_type_id' => $version?->qualification_type_id,
            'obtained_on' => $version?->obtained_on?->toDateString(),
            'created_at' => $this->created_at,
            'version_number' => $version?->version_number,
            'provenance' => $version?->provenance(),
            'is_primary' => (bool) $this->is_primary,
        ];
    }
}
