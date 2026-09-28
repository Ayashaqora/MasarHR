<?php

namespace App\Modules\HumanResources\Domain\Exceptions;

use RuntimeException;

/**
 * The `specialty_id` supplied to RecordEmploymentSpecialtyPeriod did not resolve to an ACTIVE
 * ref.specialties row at command time (ADR-S26-001 F) — mirrors S22's
 * InvalidEmploymentJobTitleException shape (inactive; or could not be re-resolved at all). Only NEW
 * periods are gated: a specialty deactivated after it was recorded never invalidates, rewrites, or
 * hides an existing period. The discriminator is identity (`id`), never display text.
 */
final class InvalidEmploymentSpecialtyException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('specialty_id must reference an active specialty.');
    }
}
