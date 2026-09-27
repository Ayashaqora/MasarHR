<?php

namespace App\Modules\HumanResources\Presentation\Http\Resources;

use App\Modules\HumanResources\Domain\ActualWorkplace;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Output shape for a resolved actual-workplace result (spec §14/§20). Wraps the
 * ActualWorkplace value object directly — never an Eloquent model — since this concept is never
 * persisted (spec §7.2).
 */
class ActualWorkplaceResource extends JsonResource
{
    public function __construct(private readonly ActualWorkplace $workplace)
    {
        parent::__construct($workplace);
    }

    public function toArray(Request $request): array
    {
        return [
            'organizational_unit_id' => $this->workplace->organizationalUnitId(),
            'source' => $this->workplace->source(),
            'since' => $this->workplace->since()?->toDateString(),
        ];
    }
}
