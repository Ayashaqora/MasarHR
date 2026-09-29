<?php

namespace App\Modules\HumanResources\Domain;

/**
 * Why an expiry follow-up was recognised as stale and suppressed
 * (docs/movement-expiry-followup-foundation-specification.md §S31.7, ADR-S31-005/006). Stable
 * machine codes, stored in automation.movement_expiry_followups.suppression_reason (the database
 * CHECK lists the same set) — never localized text.
 */
enum FollowUpSuppressionReason: string
{
    /** The movement now ends EARLIER than the follow-up expected (a newer movement, a transfer or a relationship end truncated it). */
    case TruncatedEarlier = 'TRUNCATED_EARLIER';

    /** The movement's end date changed in any other way (later, or no end date). */
    case EndDateChanged = 'END_DATE_CHANGED';

    /** The Employment Relationship ends on or before the expected end, so there is no return to act on. */
    case RelationshipEnded = 'RELATIONSHIP_ENDED';

    /** A newer movement already covers the expected end date, so the employee does not return then. */
    case CoveredByNewerMovement = 'COVERED_BY_NEWER_MOVEMENT';
}
