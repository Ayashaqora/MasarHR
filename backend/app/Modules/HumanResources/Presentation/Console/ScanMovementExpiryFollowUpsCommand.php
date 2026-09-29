<?php

namespace App\Modules\HumanResources\Presentation\Console;

use App\Modules\HumanResources\Application\Commands\ScanMovementExpiryFollowUps;
use Illuminate\Console\Command;

/**
 * The thin scheduler / operator entry point for the Movement Expiry follow-up scan
 * (docs/movement-expiry-followup-foundation-specification.md §S31.11, ADR-S31-011). It holds NO
 * business rule: it runs ScanMovementExpiryFollowUps for today's business date (from the
 * BusinessDateClock) and prints the counts. Safe to run repeatedly — the scan is idempotent — and
 * deliberately without a `--date` option, so an operator cannot back-date a run into retroactive
 * alerts. No queue job is introduced: one bounded, idempotent scan per day needs none (the S02
 * architecture makes Redis available if a worker is ever justified).
 */
class ScanMovementExpiryFollowUpsCommand extends Command
{
    protected $signature = 'masar:hr:scan-movement-expiry-followups';

    protected $description = 'Derive and emit the 7-day expiry follow-ups for bounded temporary workplace movements (idempotent).';

    public function handle(ScanMovementExpiryFollowUps $scan): int
    {
        $result = $scan->handle();

        $this->components->info(sprintf(
            'Movement expiry scan for %s: %d emitted, %d already existing, %d suppressed, %d stale before emission.',
            $result->businessDate,
            count($result->emitted),
            $result->alreadyExisting,
            count($result->suppressed),
            count($result->staleBeforeEmission),
        ));

        return self::SUCCESS;
    }
}
