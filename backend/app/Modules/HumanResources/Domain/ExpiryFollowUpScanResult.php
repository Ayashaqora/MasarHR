<?php

namespace App\Modules\HumanResources\Domain;

/**
 * What one ScanMovementExpiryFollowUps run did (docs/movement-expiry-followup-foundation-
 * specification.md §S31.11). An immutable, never-persisted summary for the scheduler command and
 * tests: ids and stable codes only. A run that finds nothing is an all-empty result and writes no
 * audit entry at all.
 */
final class ExpiryFollowUpScanResult
{
    /**
     * @param  list<string>  $emitted  follow-up ids created (status ACTIONABLE) by this run
     * @param  list<string>  $suppressed  ids of already-emitted follow-ups this run found stale
     * @param  list<array{movement_type: string, movement_id: string, reason: string}>  $staleBeforeEmission  candidates whose recheck failed immediately before emission — nothing was written
     */
    public function __construct(
        public readonly string $businessDate,
        public readonly array $emitted = [],
        public readonly int $alreadyExisting = 0,
        public readonly array $suppressed = [],
        public readonly array $staleBeforeEmission = [],
    ) {}
}
