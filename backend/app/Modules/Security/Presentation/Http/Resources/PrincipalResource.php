<?php

namespace App\Modules\Security\Presentation\Http\Resources;

use App\Modules\Security\Infrastructure\Persistence\Eloquent\Principal;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Principal
 *
 * Never exposes credentials — this resource is built from the Principal model's own visible
 * attributes only, and Principal has no password/hash attribute to begin with.
 */
class PrincipalResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'username' => $this->username,
            'display_name' => $this->display_name,
            'status' => $this->status instanceof \BackedEnum ? $this->status->value : $this->status,
            'version' => $this->version,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
