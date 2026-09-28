<?php

namespace App\Modules\HumanResources\Application\Queries;

use App\Modules\HumanResources\Domain\ActualWorkplace;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\EmploymentRelationship;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\FullSecondmentPeriod;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\OrganizationalPlacementPeriod;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\WorkplaceAssignmentPeriod;

/**
 * Resolves an Employment Relationship's current "actual workplace"
 * (docs/full-secondment-foundation-specification.md §1.5/§12/§14, extended by S16 —
 * docs/workplace-assignment-foundation-specification.md §S16.8): the destination of its
 * currently-open S12 Full Secondment period if one exists, otherwise the destination of its
 * currently-open S16 Workplace Assignment period if one exists, otherwise the unit of its
 * currently-open S11 Placement period, otherwise unresolved. The secondment/assignment check
 * order is defensive, not load-bearing: a relationship can never have both open simultaneously —
 * S16's own movement interaction matrix enforces mutual exclusion between them at write time
 * (spec §S16.8) — so there is never a genuine tie for this method to break.
 *
 * An ended relationship (end_knowledge_state = KNOWN) always resolves to unresolved(), regardless
 * of any still-open secondment/assignment/placement row (spec §7.3) — this is the one place this
 * stage's disclosed "ending an Employment Relationship does not automatically close an active
 * secondment" limitation is neutralised at read time: no caller of this query is ever told a
 * person is "currently" working somewhere once their employment has ended.
 */
final class ResolveActualWorkplaceForRelationship
{
    public function __invoke(EmploymentRelationship $relationship): ActualWorkplace
    {
        if ($relationship->end_knowledge_state === 'KNOWN') {
            return ActualWorkplace::unresolved();
        }

        $secondment = FullSecondmentPeriod::query()
            ->where('employment_relationship_id', $relationship->getKey())
            ->whereNull('effective_to')
            ->first();

        if ($secondment !== null) {
            return ActualWorkplace::fromSecondment($secondment->organizational_unit_id, $secondment->effective_from);
        }

        $assignment = WorkplaceAssignmentPeriod::query()
            ->where('employment_relationship_id', $relationship->getKey())
            ->whereNull('effective_to')
            ->first();

        if ($assignment !== null) {
            return ActualWorkplace::fromAssignment($assignment->organizational_unit_id, $assignment->effective_from);
        }

        $placement = OrganizationalPlacementPeriod::query()
            ->where('employment_relationship_id', $relationship->getKey())
            ->whereNull('effective_to')
            ->first();

        if ($placement !== null) {
            return ActualWorkplace::fromPlacement($placement->organizational_unit_id, $placement->effective_from);
        }

        return ActualWorkplace::unresolved();
    }
}
