<?php

namespace App\Modules\HumanResources\Domain;

use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\FullSecondmentPeriod;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\OrganizationalPlacementPeriod;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\WorkplaceAssignmentPeriod;

/**
 * The outcome of one TransferEmployee call (docs/transfer-foundation-specification.md §8,
 * extended by S16 — docs/workplace-assignment-foundation-specification.md §S16.8): the new S11
 * organizational placement period the transfer opened (always present — a transfer always moves
 * the original placement), the S12 full secondment period the transfer closed as its consequence
 * if one was active (§8 of the S14 authorization: "closes active secondments"), and the S16
 * workplace assignment period the transfer closed as its consequence if one was active (spec
 * §S16.8, movement interaction matrix pair "Assignment → Transfer": ALLOW,
 * CLOSE-PREVIOUS-AS-CONSEQUENCE). Immutable value object, no persistence of its own — mirrors
 * App\Modules\HumanResources\Domain\ActualWorkplace's own shape. Never itself written to a
 * database row: per ADR-S14-002/the S14 persistence-design decision, a Transfer is represented
 * entirely by the OrganizationalPlacementPeriod row it writes (S11's existing stream), the
 * FullSecondmentPeriod/WorkplaceAssignmentPeriod rows it may close (S12's/S16's existing streams),
 * and its own S04 audit entry — no new `hr.transfers` (or similarly named) table exists.
 */
final class TransferResult
{
    public function __construct(
        private readonly OrganizationalPlacementPeriod $placement,
        private readonly ?FullSecondmentPeriod $closedSecondment,
        private readonly ?WorkplaceAssignmentPeriod $closedAssignment = null,
    ) {}

    public function placement(): OrganizationalPlacementPeriod
    {
        return $this->placement;
    }

    /** Null when the relationship had no active full secondment to close. */
    public function closedSecondment(): ?FullSecondmentPeriod
    {
        return $this->closedSecondment;
    }

    /** Null when the relationship had no active workplace assignment to close (S16). */
    public function closedAssignment(): ?WorkplaceAssignmentPeriod
    {
        return $this->closedAssignment;
    }
}
