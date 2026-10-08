<?php

namespace App\Modules\HumanResources\Domain\Exceptions;

use RuntimeException;

/**
 * S48 (docs/person-qualification-history-foundation-specification.md §S48.4/§S48.5, D43):
 * RecordPersonQualification and CorrectPersonQualification both require a known acting principal
 * for every new write in the normal command path — `created_by_principal_id = NULL` is reserved
 * exclusively for the documented migration-time backfill (2026_10_22_000002), which resolves it
 * directly from historical audit entries, never through either command. Every real HTTP caller
 * already supplies a real principal id (ResolveCommandContext always builds `Actor::human()` with
 * a non-null principal id for an authenticated request), so this is defense-in-depth against a
 * direct/non-HTTP caller omitting it — rejected before anything is written, never silently
 * defaulted to an unknown actor.
 */
final class PersonQualificationActorRequiredException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('A known acting principal is required to record or correct a qualification.');
    }
}
