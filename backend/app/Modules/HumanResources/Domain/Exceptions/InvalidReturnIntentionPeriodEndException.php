<?php

namespace App\Modules\HumanResources\Domain\Exceptions;

use RuntimeException;

/** S34: effective_to is not strictly after effective_from, or would end inside an already recorded bounded period (a rewrite). */
final class InvalidReturnIntentionPeriodEndException extends RuntimeException {}
