<?php

namespace App\Modules\HumanResources\Domain;

use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\FullSecondmentPeriod;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\PartialSecondmentPeriod;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\WorkplaceAssignmentPeriod;
use Illuminate\Database\Eloquent\Model;

/**
 * The bounded temporary workplace movements that carry an expiry follow-up
 * (docs/movement-expiry-followup-foundation-specification.md §S31.3, ADR-S31-002): Full
 * Secondment (S12), Workplace Assignment (S16) and Partial Secondment (S30). Transfer is permanent
 * and Organizational Placement is not a temporary movement, so neither is here. The backing values
 * are stable machine codes stored in automation.movement_expiry_followups.movement_type.
 */
enum TemporaryMovementType: string
{
    case FullSecondment = 'FULL_SECONDMENT';
    case WorkplaceAssignment = 'WORKPLACE_ASSIGNMENT';
    case PartialSecondment = 'PARTIAL_SECONDMENT';

    /** @return class-string<Model> */
    public function modelClass(): string
    {
        return match ($this) {
            self::FullSecondment => FullSecondmentPeriod::class,
            self::WorkplaceAssignment => WorkplaceAssignmentPeriod::class,
            self::PartialSecondment => PartialSecondmentPeriod::class,
        };
    }

    /** The schema-qualified table holding this stream's periods. */
    public function table(): string
    {
        return match ($this) {
            self::FullSecondment => 'hr.full_secondment_periods',
            self::WorkplaceAssignment => 'hr.workplace_assignment_periods',
            self::PartialSecondment => 'hr.partial_secondment_periods',
        };
    }

    /** The real foreign-key column on the follow-up row for this stream (the "exclusive arc"). */
    public function followUpColumn(): string
    {
        return match ($this) {
            self::FullSecondment => 'full_secondment_period_id',
            self::WorkplaceAssignment => 'workplace_assignment_period_id',
            self::PartialSecondment => 'partial_secondment_period_id',
        };
    }
}
