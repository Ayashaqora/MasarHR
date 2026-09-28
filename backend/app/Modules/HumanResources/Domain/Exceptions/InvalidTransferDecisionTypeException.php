<?php

namespace App\Modules\HumanResources\Domain\Exceptions;

use RuntimeException;

/**
 * The `decision_type_id` supplied to TransferEmployee did not resolve to the active `ref
 * .decision_types` row whose `code` is `TRANSFER` (ADR-S14-002; "Decision rule: store ONLY
 * نوع القرار" — docs/full-secondment-foundation-specification.md §1.4, resolved by ADR-S14-002).
 * Covers three distinct causes behind one generic message, mirroring this codebase's existing
 * convention of not leaking which specific sub-reason applied (e.g.
 * ScopedAuthorizationChecker::authorize()'s identical single-403-for-three-causes shape): the
 * referenced decision type exists but its `code` is not `TRANSFER`; it exists, its `code` is
 * `TRANSFER`, but it is not `is_active`; the id could not be re-resolved at all at the moment the
 * command re-fetched it fresh inside the transaction (a benign TOCTOU outcome, not a 404, since the
 * controller already confirmed it existed moments earlier). The stable technical discriminator
 * checked is `code`, never `name_ar`/`name_en` display text (ADR-S14-002 explicit instruction).
 */
final class InvalidTransferDecisionTypeException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('decision_type_id must reference the active TRANSFER decision type.');
    }
}
