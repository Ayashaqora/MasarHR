<?php

namespace App\Modules\HumanResources\Presentation\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Output shape for a single employment contract period (S21 spec §S21.14). Both end dates are
 * EXCLUSIVE: effective_to is the actual validity end, contractual_effective_to the agreed term end.
 */
class EmploymentContractPeriodResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'employment_relationship_id' => $this->employment_relationship_id,
            'contract_type_id' => $this->contract_type_id,
            'effective_from' => $this->effective_from?->toDateString(),
            'effective_to' => $this->effective_to?->toDateString(),
            'contractual_effective_to' => $this->contractual_effective_to?->toDateString(),
            'contract_end_knowledge_state' => $this->contract_end_knowledge_state,
        ];
    }
}
