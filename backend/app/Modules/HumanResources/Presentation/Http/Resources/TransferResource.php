<?php

namespace App\Modules\HumanResources\Presentation\Http\Resources;

use App\Modules\HumanResources\Domain\TransferResult;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Output shape for one TransferEmployee call (spec §20, extended by S16 —
 * docs/workplace-assignment-foundation-specification.md §S16.8). Wraps the TransferResult value
 * object directly — never an Eloquent model of its own, since no new "transfer" row is ever
 * persisted (spec §16) — reusing
 * OrganizationalPlacementPeriodResource's/FullSecondmentPeriodResource's/
 * WorkplaceAssignmentPeriodResource's own field shapes for the nested period objects rather than
 * re-declaring them.
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
            // S16: null when no workplace assignment was active — the response still discloses
            // that this consequence was considered, not merely omitted, mirroring the secondment
            // field's own convention exactly.
            'closed_workplace_assignment_period' => $this->result->closedAssignment() !== null
                ? (new WorkplaceAssignmentPeriodResource($this->result->closedAssignment()))->toArray($request)
                : null,
        ];
    }
}
