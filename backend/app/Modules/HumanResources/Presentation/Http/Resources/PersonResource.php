<?php

namespace App\Modules\HumanResources\Presentation\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Output shape for a single Person (spec §4/§19). No name/profile fields exist in S09 v1. */
class PersonResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'national_id' => $this->national_id,
            'is_terminal' => $this->is_terminal,
            'version' => $this->version,
        ];
    }
}
