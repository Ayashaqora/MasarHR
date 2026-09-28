<?php

namespace App\Modules\HumanResources\Domain\Exceptions;

use RuntimeException;

/**
 * The `job_title_id` supplied to RecordEmploymentJobTitlePeriod did not resolve to an ACTIVE
 * ref.job_titles row at command time (ADR-S22-001 §6) — mirrors S20's
 * InvalidEmploymentCategoryException shape (inactive; or could not be re-resolved at all). Only NEW
 * assignments are gated: a job title deactivated after it was recorded never invalidates,
 * rewrites, or hides an existing period. The discriminator is identity (`id`), never display text.
 */
final class InvalidEmploymentJobTitleException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('job_title_id must reference an active job title.');
    }
}
