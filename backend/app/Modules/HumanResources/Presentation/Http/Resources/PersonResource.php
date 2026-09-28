<?php

namespace App\Modules\HumanResources\Presentation\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Output shape for a single Person (spec §4/§19). S24 adds the current profile
 * (docs/person-profile-foundation-specification.md §S24.11): full_name_ar, gender_id,
 * marital_status_id, birth_date, birth_place. References are exposed as stable ids — the same
 * convention every HR resource uses; display labels come from the /reference catalogs. Legacy
 * pre-S24 values are returned as null — never a fabricated display string. No age is returned: it
 * is always derived from birth_date by the consumer at its own as-of date.
 */
class PersonResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'national_id' => $this->national_id,
            'is_terminal' => $this->is_terminal,
            'version' => $this->version,
            'full_name_ar' => $this->full_name_ar,
            'gender_id' => $this->gender_id,
            'marital_status_id' => $this->marital_status_id,
            'birth_date' => $this->birth_date?->toDateString(),
            'birth_place' => $this->birth_place,
        ];
    }
}
