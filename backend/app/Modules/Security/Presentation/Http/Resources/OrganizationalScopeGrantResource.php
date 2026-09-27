<?php

namespace App\Modules\Security\Presentation\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Output shape for a single organizational-scope grant (spec §19). No `version` — spec §14. */
class OrganizationalScopeGrantResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'principal_id' => $this->principal_id,
            'scope_kind' => $this->scope_kind,
            'organizational_unit_id' => $this->organizational_unit_id,
            'granted_at' => $this->granted_at?->toIso8601String(),
            'granted_by' => $this->granted_by,
        ];
    }
}
