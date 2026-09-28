<?php

namespace App\Modules\HumanResources\Presentation\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Output shape for a single Person qualification fact (S23 spec §S23.13). */
class PersonQualificationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'person_id' => $this->person_id,
            'academic_degree_id' => $this->academic_degree_id,
            'qualification_type_id' => $this->qualification_type_id,
        ];
    }
}
