<?php

namespace App\Modules\HumanResources\Domain;

/**
 * Why a status expiry follow-up was recognised as stale and suppressed
 * (docs/employment-status-expiry-followup-specification.md §S38.6). Stable machine codes, stored in
 * automation.employment_status_expiry_followups.suppression_reason (the database CHECK lists the same set).
 * Deliberately NOT S31's FollowUpSuppressionReason: the movement-only COVERED_BY_NEWER_MOVEMENT does not exist here.
 */
enum StatusFollowUpSuppressionReason: string
{
    /** The Employment Relationship ends on or before the expected end: no return to act on. Wins over every other reason. */
    case RelationshipEnded = 'RELATIONSHIP_ENDED';

    /** The status period now ends EARLIER than the follow-up expected (an ordinary truncation, not the relationship end). */
    case TruncatedEarlier = 'TRUNCATED_EARLIER';

    /** An explicit successor status already starts exactly at the expected end. */
    case SuccessorRecorded = 'SUCCESSOR_RECORDED';
}
