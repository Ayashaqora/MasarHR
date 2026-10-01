<?php

namespace App\Modules\HumanResources\Domain;

use App\Modules\HumanResources\Domain\Exceptions\InconsistentDimensionHistoryException;
use DateTimeImmutable;
use DateTimeZone;

/**
 * REPORT-1 cumulative ACTUAL service (docs/human-cadre-report-foundation-specification.md §S41.11, R1-D32/D34/D35/D36/D40/D47/D48).
 * Pure and deterministic: no clock, no database. The canonical value is an integer COUNT of calendar days over half-open
 * [from, to) DATE intervals (day numbers, so no timezone or month arithmetic), never a sum of per-interval years/months/days.
 *
 * Service derives ONLY from the Employment Relationships documented in MasarHR (no prior service, no first-hire estimate), each
 * clipped to [effective_from, min(actual effective_to or the report boundary, boundary)): the ACTUAL end controls (a contract's
 * agreed term is not an input here at all), gaps between relationships count for nothing, and relationships cannot overlap
 * (GiST), so no date is counted twice. Within a relationship, the explicit statuses (GiST no-overlap, so 0 or 1 at any date)
 * decide whether each interval counts:
 *   no status (baseline) COUNT · captive / suspended / on_duty / any other or future status COUNT · unpaid_leave DO_NOT_COUNT ·
 *   traveling PAID COUNT, UNPAID DO_NOT_COUNT, NULL COUNT by default (and the TRAVEL_PAY_STATUS_NOT_RECORDED flag) ·
 *   external_sick_leave: the first 90 calendar days of each CONTINUOUS RUN count, day 91 onward does not; adjacent periods with
 *   no date gap are one run, any other status or a date gap starts a new run, a run may start before the report month and the
 *   relationship boundary / report boundary clip it.
 * A relationship whose actual end is UNKNOWN_LEGACY makes the Person's service INCOMPLETE (never a fabricated end).
 */
final class CumulativeServiceCalculator
{
    public const CALCULABLE = 'CALCULABLE';

    public const INCOMPLETE = 'INCOMPLETE';

    public const REASON_UNKNOWN_LEGACY = 'UNKNOWN_LEGACY_RELATIONSHIP_END';

    public const SICK_LEAVE_ALLOWANCE_DAYS = 90;

    /** @var list<string> the official service bands, in display order (INCOMPLETE last) */
    public const BANDS = ['<5', '5-9', '10-14', '15-19', '20-24', '25-29', '30+', self::INCOMPLETE];

    /**
     * @param  list<array{id: string, effective_from: string, effective_to: string|null, end_knowledge_state: string}>  $relationships  every documented relationship starting before the boundary
     * @param  array<string, list<array{status_code: string, effective_from: string, effective_to: string|null, travel_pay_status: string|null}>>  $statusesByRelationship
     * @param  string  $boundary  the exclusive report boundary (the first day of the next month)
     * @return array{state: string, service_days: int|null, reason: string|null, travel_pay_not_recorded: bool}
     */
    public static function calculate(array $relationships, array $statusesByRelationship, string $boundary): array
    {
        $days = 0;
        $travelPayNotRecorded = false;
        $boundaryDay = self::day($boundary);

        usort($relationships, fn (array $a, array $b) => [$a['effective_from'], $a['id']] <=> [$b['effective_from'], $b['id']]);

        $previousEnd = null;
        foreach ($relationships as $relationship) {
            if ($relationship['end_knowledge_state'] === 'UNKNOWN_LEGACY') {
                return ['state' => self::INCOMPLETE, 'service_days' => null, 'reason' => self::REASON_UNKNOWN_LEGACY, 'travel_pay_not_recorded' => false];
            }

            $from = self::day($relationship['effective_from']);
            $end = min($relationship['effective_to'] === null ? $boundaryDay : self::day($relationship['effective_to']), $boundaryDay);
            if ($from >= $end) {
                continue;
            }
            if ($previousEnd !== null && $from < $previousEnd) {
                throw new InconsistentDimensionHistoryException('Two employment relationships of one Person overlap; the database exclusion constraint forbids this.');
            }
            $previousEnd = $end;

            [$counted, $travelNull] = self::relationshipDays($from, $end, $statusesByRelationship[$relationship['id']] ?? []);
            $days += $counted;
            $travelPayNotRecorded = $travelPayNotRecorded || $travelNull;
        }

        return ['state' => self::CALCULABLE, 'service_days' => $days, 'reason' => null, 'travel_pay_not_recorded' => $travelPayNotRecorded];
    }

    /** @return array{years: int, days: int, band: string} */
    public static function breakdown(int $serviceDays): array
    {
        $years = intdiv($serviceDays, 365);

        return ['years' => $years, 'days' => $serviceDays % 365, 'band' => self::band($years)];
    }

    /** The band of a number of COMPLETED service years (floor(days / 365)): 0-4 → <5 … 30+. */
    public static function band(int $completedYears): string
    {
        return match (true) {
            $completedYears < 5 => '<5',
            $completedYears < 10 => '5-9',
            $completedYears < 15 => '10-14',
            $completedYears < 20 => '15-19',
            $completedYears < 25 => '20-24',
            $completedYears < 30 => '25-29',
            default => '30+',
        };
    }

    /**
     * The counted days of one relationship window [$from, $end) (day numbers) under its explicit statuses.
     *
     * @param  list<array{status_code: string, effective_from: string, effective_to: string|null, travel_pay_status: string|null}>  $statuses
     * @return array{0: int, 1: bool} counted days, and whether a NULL-pay traveling interval was counted
     */
    private static function relationshipDays(int $from, int $end, array $statuses): array
    {
        usort($statuses, fn (array $a, array $b) => $a['effective_from'] <=> $b['effective_from']);

        $counted = 0;
        $travelNull = false;
        $cursor = $from;
        $previousStatusEnd = null;
        $previousWasSick = false;
        $runStart = null;

        foreach ($statuses as $status) {
            $start = max(self::day($status['effective_from']), $from);
            $stop = min($status['effective_to'] === null ? $end : self::day($status['effective_to']), $end);
            if ($start >= $stop) {
                continue;
            }
            if ($start < $cursor) {
                throw new InconsistentDimensionHistoryException('Two status periods of one relationship overlap; the database exclusion constraint forbids this.');
            }

            $counted += $start - $cursor; // baseline: an active relationship with no explicit status counts

            $length = $stop - $start;
            switch ($status['status_code']) {
                case 'unpaid_leave':
                    $previousWasSick = false;
                    break;
                case 'traveling':
                    $previousWasSick = false;
                    if ($status['travel_pay_status'] === 'UNPAID') {
                        break;
                    }
                    $counted += $length;
                    if ($status['travel_pay_status'] === null) {
                        $travelNull = true;
                    }
                    break;
                case 'external_sick_leave':
                    if (! ($previousWasSick && $previousStatusEnd === $start)) {
                        $runStart = $start; // a date gap or a different status in between: a NEW run, a new allowance
                    }
                    $counted += max(0, min($stop, $runStart + self::SICK_LEAVE_ALLOWANCE_DAYS) - $start);
                    $previousWasSick = true;
                    break;
                default: // captive, suspended, on_duty and any other or future status
                    $previousWasSick = false;
                    $counted += $length;
            }

            $previousStatusEnd = $stop;
            $cursor = $stop;
        }

        $counted += $end - $cursor;

        return [$counted, $travelNull];
    }

    private static function day(string $date): int
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date, new DateTimeZone('UTC'));

        return intdiv($parsed->getTimestamp(), 86400);
    }
}
