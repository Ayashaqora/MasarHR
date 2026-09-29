<?php

namespace App\Modules\Platform\Application\Clock;

use Carbon\CarbonImmutable;

/**
 * The single application boundary that answers "what is today's business date?"
 * (docs/movement-expiry-followup-foundation-specification.md §S31.12, ADR-S31-012). Domain and
 * application code never call today()/now() to decide a business date: the scheduler-driven
 * services take this clock (or an explicit date), so tests bind a fixed clock and stay
 * deterministic. It defines NO timezone policy of its own — see SystemBusinessDateClock.
 */
interface BusinessDateClock
{
    /** Today's business date at 00:00 in the application's configured timezone. */
    public function today(): CarbonImmutable;
}
