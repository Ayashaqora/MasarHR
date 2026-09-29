<?php

namespace Tests\Support;

use App\Modules\Platform\Application\Clock\BusinessDateClock;
use Carbon\CarbonImmutable;

/**
 * A deterministic BusinessDateClock for tests (S31, ADR-S31-012): the business date is whatever
 * the test says, never the wall clock.
 */
final class FixedBusinessDateClock implements BusinessDateClock
{
    public function __construct(private string $date) {}

    public function on(string $date): self
    {
        $this->date = $date;

        return $this;
    }

    public function today(): CarbonImmutable
    {
        return CarbonImmutable::parse($this->date)->startOfDay();
    }
}
