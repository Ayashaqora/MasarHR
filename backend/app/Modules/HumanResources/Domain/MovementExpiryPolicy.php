<?php

namespace App\Modules\HumanResources\Domain;

use Illuminate\Support\Carbon;

/**
 * The frozen 7-day rule (docs/movement-expiry-followup-foundation-specification.md §S31.4,
 * ADR-S31-003): a bounded temporary movement ending on business date E is warned about from
 * E − 7 CALENDAR days. DATE arithmetic only — never a working week, never a hard-coded weekend.
 * The same arithmetic is enforced by the database CHECK on automation.movement_expiry_followups.
 */
final class MovementExpiryPolicy
{
    public const KIND = 'EXPIRY_WARNING_7D';

    public const WARNING_LEAD_DAYS = 7;

    /** Y-m-d due date for a movement whose effective_to (exclusive) is $expectedEffectiveTo. */
    public static function dueDate(string $expectedEffectiveTo): string
    {
        return Carbon::parse($expectedEffectiveTo)->subDays(self::WARNING_LEAD_DAYS)->toDateString();
    }

    /**
     * True when $businessDate lies in the actionable window [E − 7, E): due, and the movement has
     * not yet ended (an expired movement is history — no retroactive warning, ADR-S31-017).
     */
    public static function isInWindow(string $expectedEffectiveTo, string $businessDate): bool
    {
        return self::dueDate($expectedEffectiveTo) <= $businessDate && $businessDate < $expectedEffectiveTo;
    }
}
