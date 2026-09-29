<?php

namespace App\Modules\HumanResources\Domain\Exceptions;

use RuntimeException;

/**
 * S32 (docs/bounded-temporary-employment-status-lifecycle-specification.md §S32.3): the supplied
 * effective_to for an employment-status period is unsupported for the status code, missing where
 * required, not strictly after effective_from, or would require rewriting recorded history.
 */
final class InvalidStatusPeriodEndException extends RuntimeException {}
