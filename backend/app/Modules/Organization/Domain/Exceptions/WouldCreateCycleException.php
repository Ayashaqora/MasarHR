<?php

namespace App\Modules\Organization\Domain\Exceptions;

use RuntimeException;

/**
 * The requested move would make a unit its own ancestor or descendant (spec §13/§26). Detected by
 * a recursive-CTE ancestor check inside MoveOrganizationalUnit's transaction, itself serialized
 * against other concurrent moves by a pg_advisory_xact_lock so no two moves can race the check.
 * Maps to 409, alongside StaleVersionException — both are "the operation conflicts with the
 * current state of the resource" (same convention as the Reference module's
 * OverlappingBehaviorPeriodException/StaleVersionException sharing 409, spec §26).
 */
final class WouldCreateCycleException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('This move would make the unit its own ancestor or descendant.');
    }
}
