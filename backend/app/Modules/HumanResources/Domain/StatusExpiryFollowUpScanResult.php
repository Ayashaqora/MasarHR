<?php

namespace App\Modules\HumanResources\Domain;

/**
 * What one ScanEmploymentStatusExpiryFollowUps run did (docs/employment-status-expiry-followup-specification.md
 * §S38.10). An immutable, never-persisted summary for the scheduler command and tests: ids and stable codes
 * only. A run that finds nothing is an all-empty result and writes no audit entry at all.
 */
final class StatusExpiryFollowUpScanResult
{
    /**
     * @param  list<string>  $emitted  follow-up ids created (status ACTIONABLE) by this run
     * @param  list<string>  $suppressed  ids of already-emitted follow-ups this run found stale
     * @param  list<array{employment_status_period_id: string, reason: string}>  $notCreated  candidates the recheck rejected before emission — nothing was written
     */
    public function __construct(
        public readonly string $businessDate,
        public readonly array $emitted = [],
        public readonly int $alreadyExisting = 0,
        public readonly array $suppressed = [],
        public readonly array $notCreated = [],
    ) {}
}
