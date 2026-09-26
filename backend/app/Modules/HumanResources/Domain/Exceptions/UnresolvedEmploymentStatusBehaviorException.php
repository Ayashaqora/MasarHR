<?php

namespace App\Modules\HumanResources\Domain\Exceptions;

use RuntimeException;

/**
 * ResolveEmploymentStatusDetailBehaviorAsOf (S06) returned null for the given effective_from — no
 * behavior period covers that date (S06's seeded periods are authoritative from 2026-09-26
 * forward only; see docs/versioned-behavior-reporting-references-specification.md §11). Never
 * defaulted to a guessed behavior — matches the established UNRESOLVED contract used throughout
 * this codebase (docs/employment-status-history-foundation-specification.md §7.6/§9).
 */
final class UnresolvedEmploymentStatusBehaviorException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('No behavior period is defined for this employment status detail as of the given effective_from date.');
    }
}
