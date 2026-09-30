<?php

namespace App\Modules\HumanResources\Presentation\Console;

use App\Modules\HumanResources\Application\Commands\ScanEmploymentStatusExpiryFollowUps;
use Illuminate\Console\Command;

/**
 * S38's daily scheduled entry point (docs/employment-status-expiry-followup-specification.md §S38.12). Independent of
 * S31's movement command: it calls only the idempotent ScanEmploymentStatusExpiryFollowUps service, which holds all
 * the rules, so a repeated or overlapping run is harmless.
 */
class ScanEmploymentStatusExpiryFollowUpsCommand extends Command
{
    protected $signature = 'masar:hr:scan-employment-status-expiry-followups';

    protected $description = 'Derive and emit the 7-day expiry follow-ups for bounded temporary employment statuses (idempotent).';

    public function handle(ScanEmploymentStatusExpiryFollowUps $scan): int
    {
        $result = $scan->handle();

        $this->components->info(sprintf(
            'Employment status expiry scan for %s: %d emitted, %d already existing, %d suppressed, %d not created (stale before emission).',
            $result->businessDate,
            count($result->emitted),
            $result->alreadyExisting,
            count($result->suppressed),
            count($result->notCreated),
        ));

        return self::SUCCESS;
    }
}
