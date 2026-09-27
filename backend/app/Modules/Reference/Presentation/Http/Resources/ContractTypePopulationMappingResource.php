<?php

namespace App\Modules\Reference\Presentation\Http\Resources;

use App\Modules\Reference\Infrastructure\Persistence\Eloquent\ContractTypePopulationMapping;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ContractTypePopulationMapping */
class ContractTypePopulationMappingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'contract_type_id' => $this->contract_type_id,
            'population_category_id' => $this->population_category_id,
            'effective_from' => $this->effective_from?->toDateString(),
            'effective_to' => $this->effective_to?->toDateString(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
