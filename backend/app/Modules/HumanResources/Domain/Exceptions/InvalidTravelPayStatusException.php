<?php

namespace App\Modules\HumanResources\Domain\Exceptions;

use RuntimeException;

/**
 * S41 (docs/human-cadre-report-foundation-specification.md §S41.5): a travel_pay_status was supplied for a status other than
 * `traveling`, or with a value other than PAID / UNPAID (NULL is always accepted and means "not recorded").
 */
final class InvalidTravelPayStatusException extends RuntimeException {}
