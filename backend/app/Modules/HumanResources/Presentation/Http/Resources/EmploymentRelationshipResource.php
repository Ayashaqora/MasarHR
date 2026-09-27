<?php

namespace App\Modules\HumanResources\Presentation\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Output shape for a single Employment Relationship (spec §6/§19). */
class EmploymentRelationshipResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'person_id' => $this->person_id,
            'employment_type_id' => $this->employment_type_id,
            'employee_number' => $this->employee_number,
            'employee_number_scheme' => $this->employee_number_scheme,
            'effective_from' => $this->effective_from?->toDateString(),
            'effective_to' => $this->effective_to?->toDateString(),
            'end_knowledge_state' => $this->end_knowledge_state,
            'ended_terminally' => $this->ended_terminally,
            'version' => $this->version,
        ];
    }
}
