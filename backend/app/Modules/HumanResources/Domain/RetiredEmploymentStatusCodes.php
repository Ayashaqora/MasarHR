<?php

namespace App\Modules\HumanResources\Domain;

/**
 * S34: the two catalog codes that were modelled as employment statuses before Return Intention was
 * separated into its own concept. The catalog rows are kept (RESTRICT FK, historical reads) but they
 * are retired from NEW status recording by a code-level guard — never by `is_active` alone, which an
 * administrator can flip back.
 */
final class RetiredEmploymentStatusCodes
{
    public const CODES = ['wants_to_return', 'does_not_want_to_return'];

    public static function isRetired(string $code): bool
    {
        return in_array($code, self::CODES, true);
    }
}
