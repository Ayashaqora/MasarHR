<?php

namespace App\Modules\Reference\Domain\Exceptions;

use RuntimeException;

/**
 * The proposed [effective_from, effective_to) period overlaps an existing period for the same
 * employment status detail (S05 §12) — surfaced by ref.employment_status_detail_behaviors'
 * PostgreSQL EXCLUDE constraint (SQLSTATE 23P01), translated here into a 409 domain rejection.
 */
final class OverlappingBehaviorPeriodException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('This period overlaps an existing behavior period for this employment status detail.');
    }
}
