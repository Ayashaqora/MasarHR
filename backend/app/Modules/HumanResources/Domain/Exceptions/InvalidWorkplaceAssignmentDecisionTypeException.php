<?php

namespace App\Modules\HumanResources\Domain\Exceptions;

use RuntimeException;

/**
 * The `decision_type_id` supplied to StartWorkplaceAssignment did not resolve to the active
 * `ref.decision_types` row whose `code` is `ASSIGNMENT` (ADR-S16-001 §14;
 * docs/workplace-assignment-foundation-specification.md §S16.12) — mirrors
 * InvalidTransferDecisionTypeException's identical shape verbatim, including covering three
 * distinct causes behind one generic message (wrong code; inactive; could not be re-resolved at
 * all, a benign TOCTOU outcome). The stable technical discriminator checked is `code`, never
 * `name_ar`/`name_en` display text.
 */
final class InvalidWorkplaceAssignmentDecisionTypeException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('decision_type_id must reference the active ASSIGNMENT decision type.');
    }
}
