<?php

namespace App\Modules\HumanResources\Domain\Exceptions;

use RuntimeException;

/**
 * The `contract_type_id` supplied to RecordEmploymentContractPeriod did not resolve to an ACTIVE
 * ref.contract_types row at command time (ADR-S21-001 §7) — mirrors S20's
 * InvalidEmploymentCategoryException shape, including covering two causes behind one generic
 * message (inactive; could not be re-resolved at all, a benign TOCTOU outcome). Only NEW
 * assignments are gated: a contract type deactivated after it was recorded never invalidates,
 * rewrites, or hides an existing period. The discriminator is identity (`id`), never display text.
 */
final class InvalidEmploymentContractTypeException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('contract_type_id must reference an active contract type.');
    }
}
