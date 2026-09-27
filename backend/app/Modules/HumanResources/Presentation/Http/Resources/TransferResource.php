<?php

namespace App\Modules\HumanResources\Presentation\Http\Resources;

use App\Modules\HumanResources\Domain\TransferResult;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Output shape for one TransferEmployee call (spec §20). Wraps the TransferResult value object
 * directly — never an Eloquent model of its own, since no new "transfer" row is ever persisted
 * (spec §16) — reusing OrganizationalPlacementPeriodResource's/FullSecondmentPeriodResource's own
 * field shapes for the nested period objects rather than re-declaring them.
 */
class TransferResource extends JsonResource
{
    public function __construct(private readonly TransferResult $result)
    {
        parent::__construct($result);
    }

    public function toArray(Request $request): array
    {
        return [
            'organizational_placement_period' => (new OrganizationalPlacementPeriodResource($this->result->placement()))->toArray($request),
            'closed_full_secondment_period' => $this->result->closedSecondment() !== null
                ? (new FullSecondmentPeriodResource($this->result->closedSecondment()))->toArray($request)
                : null,
        ];
    }
}
