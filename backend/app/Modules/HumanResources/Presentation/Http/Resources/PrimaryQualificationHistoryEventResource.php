<?php

namespace App\Modules\HumanResources\Presentation\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * S48 primary-history event item shape, frozen verbatim (docs/person-qualification-history-foundation-specification.md
 * §S48.14). Wraps a PrimaryQualificationHistoryEvent value object (never an Eloquent model —
 * events are built entirely from audit.audit_entries, §S48.10).
 */
class PrimaryQualificationHistoryEventResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'type' => $this->resource->type,
            'qualification_id' => $this->resource->qualificationId,
            'previous_primary_qualification_id' => $this->resource->previousPrimaryQualificationId,
            'actor_principal_id' => $this->resource->actorPrincipalId,
            'occurred_at' => $this->resource->occurredAt,
        ];
    }
}
