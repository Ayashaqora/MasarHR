<?php

namespace App\Modules\HumanResources\Domain;

use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * One reporting month as the half-open DATE interval [month_start, next_month_start)
 * (docs/monthly-workforce-reporting-semantics-foundation-specification.md §S37.3). The same [from, to)
 * convention every HR temporal stream already uses: a period that ends ON month_start does not touch the
 * month, and one that ends AT next_month_start covers it through the last day.
 *
 * Canonical overlap: from < next_month_start AND (to IS NULL OR to > month_start). Dates are Y-m-d strings,
 * so string comparison is chronological. Pure: no clock, no database.
 */
final class MonthInterval
{
    private function __construct(
        public readonly string $start,
        public readonly string $next,
    ) {}

    /** @param string|Carbon $monthStart the FIRST day of the month, explicit (never derived from today) */
    public static function of(string|Carbon $monthStart): self
    {
        $date = $monthStart instanceof Carbon ? $monthStart->toDateString() : $monthStart;

        if (! preg_match('/^\d{4}-\d{2}-01$/', $date) || ! checkdate((int) substr($date, 5, 2), 1, (int) substr($date, 0, 4))) {
            throw new InvalidArgumentException('month_start must be an explicit Y-m-01 date (the first day of the reporting month).');
        }

        return new self($date, Carbon::createFromFormat('!Y-m-d', $date)->addMonthNoOverflow()->toDateString());
    }

    public function overlaps(string $from, ?string $to): bool
    {
        return $from < $this->next && ($to === null || $to > $this->start);
    }

    /**
     * The overlapping part of [$from, $to) clipped to the month, or null when they do not overlap.
     *
     * @return array{0: string, 1: string}|null
     */
    public function clip(string $from, ?string $to): ?array
    {
        return self::intersect($from, $to, $this->start, $this->next);
    }

    /**
     * Intersection of [$from, $to) (null $to = open-ended) with [$aFrom, $aTo), or null when empty.
     *
     * @return array{0: string, 1: string}|null
     */
    public static function intersect(string $from, ?string $to, string $aFrom, string $aTo): ?array
    {
        $start = max($from, $aFrom);
        $end = $to === null ? $aTo : min($to, $aTo);

        return $start < $end ? [$start, $end] : null;
    }
}
