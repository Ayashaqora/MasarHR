<?php

namespace App\Modules\Reference\Presentation\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Shared output shape for the four simple reference-value families (Gender, MaritalStatus,
 * DecisionType, EmploymentStatusCategory) — pure display code with no dynamic behavior, so it
 * carries none of the risk the S05 specification (§14/D3) discloses for the command-layer bases.
 */
class SimpleReferenceValueResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name_ar' => $this->name_ar,
            'name_en' => $this->name_en,
            'is_active' => (bool) $this->is_active,
            'display_order' => $this->display_order,
            'version' => $this->version,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
