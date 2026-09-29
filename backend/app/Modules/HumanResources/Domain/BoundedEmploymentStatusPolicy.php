<?php

namespace App\Modules\HumanResources\Domain;

/**
 * S32 (docs/bounded-temporary-employment-status-lifecycle-specification.md §S32.2, ADR-S32-002): the
 * frozen allow-list of status codes that may carry an `effective_to`, and which of them require one.
 * A code constant — no ref.* schema change. Every other code (captive included) is open-ended only.
 */
final class BoundedEmploymentStatusPolicy
{
    /** Bounded codes whose `effective_to` is optional. */
    public const OPTIONAL_END = ['traveling', 'suspended'];

    /** Bounded codes whose `effective_to` is required. */
    public const REQUIRED_END = ['unpaid_leave', 'external_sick_leave'];

    /** The status derived once a bounded period ends with no explicit successor (ADR-S32-003). */
    public const DERIVED_RETURN_CODE = 'on_duty';

    /** @return list<string> */
    public static function boundedCodes(): array
    {
        return [...self::OPTIONAL_END, ...self::REQUIRED_END];
    }

    public static function supportsEnd(string $code): bool
    {
        return in_array($code, self::boundedCodes(), true);
    }

    public static function requiresEnd(string $code): bool
    {
        return in_array($code, self::REQUIRED_END, true);
    }
}
