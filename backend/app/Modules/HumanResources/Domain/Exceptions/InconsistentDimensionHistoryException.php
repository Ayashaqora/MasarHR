<?php

namespace App\Modules\HumanResources\Domain\Exceptions;

use RuntimeException;

/**
 * S40: a monthly dimension history that contradicts an invariant the database or a frozen domain rule already
 * guarantees (two periods of one stream overlapping, or a contract recorded on a relationship the contract rule
 * does not apply to). Never reachable through the existing write commands; raised so a corrupt history fails
 * loudly instead of silently selecting one of the competing values.
 */
final class InconsistentDimensionHistoryException extends RuntimeException {}
