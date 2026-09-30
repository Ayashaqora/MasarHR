<?php

namespace App\Modules\HumanResources\Domain;

use Illuminate\Support\Carbon;

/**
 * S38's own frozen policy (docs/employment-status-expiry-followup-specification.md §S38.2–§S38.4): which
 * temporary employment status codes get an expiry follow-up, and when. It is an EXPLICIT allow-list — never
 * inferred from S32's generic "bounded" behavior — and it owns its OWN lead time: the Owner decided status
 * warnings come 7 calendar days before the end. That is an independent S38 rule; it is not S31's movement
 * lead time (MovementExpiryPolicy), which this class deliberately does not reference.
 *
 *  - traveling, suspended: eligible only when an end (effective_to) exists;
 *  - unpaid_leave, external_sick_leave: eligible (S32 requires their end);
 *  - captive: NOT eligible (source: no warning). No other code is eligible.
 *
 * The same DATE arithmetic is enforced by the database CHECK on
 * automation.employment_status_expiry_followups (due_date = expected_effective_to − 7).
 */
final class EmploymentStatusExpiryPolicy
{
    public const KIND = 'EXPIRY_WARNING_7D';

    public const WARNING_LEAD_DAYS = 7;

    /** The frozen source-authorized eligible codes (each still requires a non-null end). */
    public const ELIGIBLE_CODES = ['traveling', 'suspended', 'unpaid_leave', 'external_sick_leave'];

    public static function isEligible(string $statusCode, ?string $effectiveTo): bool
    {
        return $effectiveTo !== null && in_array($statusCode, self::ELIGIBLE_CODES, true);
    }

    /** Y-m-d due date for a status period whose effective_to (exclusive) is $expectedEffectiveTo. */
    public static function dueDate(string $expectedEffectiveTo): string
    {
        return Carbon::parse($expectedEffectiveTo)->subDays(self::WARNING_LEAD_DAYS)->toDateString();
    }

    /**
     * True when $businessDate lies in the actionable window [E − 7, E): due, and the status has not yet ended.
     * An expired status is history — no new warning is created at or after E.
     */
    public static function isInWindow(string $expectedEffectiveTo, string $businessDate): bool
    {
        return self::dueDate($expectedEffectiveTo) <= $businessDate && $businessDate < $expectedEffectiveTo;
    }
}
