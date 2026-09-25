<?php

namespace App\Modules\Reference\Presentation\Http\Resources;

use App\Modules\Reference\Infrastructure\Persistence\Eloquent\SpecialtyCadreCategoryMapping;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin SpecialtyCadreCategoryMapping */
class SpecialtyCadreCategoryMappingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'specialty_id' => $this->specialty_id,
            'cadre_category_id' => $this->cadre_category_id,
            'effective_from' => $this->effective_from?->toDateString(),
            'effective_to' => $this->effective_to?->toDateString(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
