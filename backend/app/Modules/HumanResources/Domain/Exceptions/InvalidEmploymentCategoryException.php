<?php

namespace App\Modules\HumanResources\Domain\Exceptions;

use RuntimeException;

/**
 * The `employment_category_id` supplied to RecordEmploymentCategoryPeriod did not resolve to an
 * active ref.employment_categories row at command time (ADR-S20-001 §6;
 * docs/employment-category-history-foundation-specification.md §S20.10) — mirrors
 * InvalidWorkplaceAssignmentDecisionTypeException's (S16) shape, including covering two distinct
 * causes behind one generic message (inactive; could not be re-resolved at all, a benign TOCTOU
 * outcome). The discriminator is the row's identity (`id`), never `name_ar`/`name_en` display
 * text. Only NEW assignments are gated: a category deactivated after it was recorded never
 * invalidates, rewrites, or hides an existing period.
 */
final class InvalidEmploymentCategoryException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('employment_category_id must reference an active employment category.');
    }
}
