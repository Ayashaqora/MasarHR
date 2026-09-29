<?php

namespace App\Modules\Platform\Application\Clock;

use Carbon\CarbonImmutable;

/**
 * Production clock: the business date is the calendar date in the application's existing
 * `config('app.timezone')` (UTC in this repository) — no timezone policy is invented here.
 */
final class SystemBusinessDateClock implements BusinessDateClock
{
    public function today(): CarbonImmutable
    {
        return CarbonImmutable::now((string) config('app.timezone'))->startOfDay();
    }
}
