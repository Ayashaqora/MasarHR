<?php

namespace App\Modules\HumanResources\Application\Queries;

use App\Modules\HumanResources\Domain\ActualWorkplaceAsOf;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\EmploymentRelationship;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\FullSecondmentPeriod;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\OrganizationalPlacementPeriod;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\WorkplaceAssignmentPeriod;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * EFFECTIVE BUSINESS-DATE view of an Employment Relationship's actual workplace
 * (docs/reporting-as-of-foundation-specification.md §S27.7, CA-S27-01/CA-S27-02). Unlike the
 * unchanged S12/S16 ResolveActualWorkplaceForRelationship (latest recorded / open-period view), a
 * future-start movement is not effective before its start, a future-end movement stays effective
 * until its effective_to, and a future relationship end does not end the relationship early.
 *
 * Steps: the relationship must be effective on the date (start <= date, and a KNOWN end strictly
 * after it); then the placement and every implemented temporary movement (full secondment,
 * workplace assignment) effective on the date are resolved independently and passed to the single
 * ActualWorkplaceAsOf::fromEffectiveFacts() decision table — one movement wins over placement;
 * two competing movements are AMBIGUOUS_MOVEMENT_STATE, never a chosen winner. Partial secondment
 * is not implemented and is therefore not considered; a future stage adds its stream as another
 * independent input without changing this contract.
 */
final class ResolveActualWorkplaceForRelationshipAsOf
{
    public function __invoke(EmploymentRelationship $relationship, string|Carbon $date): ActualWorkplaceAsOf
    {
        $date = $date instanceof Carbon ? $date->toDateString() : $date;

        if ($relationship->effective_from->toDateString() > $date) {
            return ActualWorkplaceAsOf::unresolved();
        }

        if ($relationship->end_knowledge_state === 'KNOWN' && $relationship->effective_to !== null && $relationship->effective_to->toDateString() <= $date) {
            return ActualWorkplaceAsOf::unresolved();
        }

        return ActualWorkplaceAsOf::fromEffectiveFacts(
            $this->effective(OrganizationalPlacementPeriod::class, $relationship, $date),
            $this->effective(FullSecondmentPeriod::class, $relationship, $date),
            $this->effective(WorkplaceAssignmentPeriod::class, $relationship, $date),
        );
    }

    /**
     * @param  class-string<Model>  $modelClass
     * @return array{id: string, organizational_unit_id: string, effective_from: string}|null
     */
    private function effective(string $modelClass, EmploymentRelationship $relationship, string $date): ?array
    {
        $period = $modelClass::query()
            ->where('employment_relationship_id', $relationship->getKey())
            ->where('effective_from', '<=', $date)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>', $date))
            ->first();

        return $period === null ? null : [
            'id' => $period->getKey(),
            'organizational_unit_id' => $period->organizational_unit_id,
            'effective_from' => $period->effective_from->toDateString(),
        ];
    }
}
