<?php

namespace App\Modules\HumanResources\Domain;

/**
 * Status segmentation of ONE Employment Relationship's ACTIVE days inside a reporting month
 * (docs/monthly-workforce-reporting-semantics-foundation-specification.md §S37.6/§S37.7/§S37.8).
 *
 * Every active day is exactly one of:
 *  - EXPLICIT: covered by a persisted employment_status_periods row (any code, on_duty included);
 *  - DERIVED_ON_DUTY: the S32 read-time return (ADR-S32-003) — after an allow-listed bounded period ends
 *    with no explicit successor, the status is on_duty until the next explicit period starts. Mirrors the
 *    S27 rule exactly: the latest period starting on/before the day has ended on/before it AND its code is
 *    in BoundedEmploymentStatusPolicy. Never persisted;
 *  - UNRESOLVED: neither. A gap is NEVER coerced: "no status row" is not on_duty and is not not-on-duty.
 *
 * Pure: no database, no clock. Segments are returned in chronological order.
 */
final class MonthlyStatusSegmentation
{
    public const EXPLICIT = 'EXPLICIT';

    public const DERIVED_ON_DUTY = 'DERIVED_ON_DUTY';

    public const UNRESOLVED = 'UNRESOLVED';

    /**
     * @param  string  $activeFrom  first active day inside the month (inclusive)
     * @param  string  $activeTo  end of the active days inside the month (exclusive)
     * @param  list<array{id: string, status_detail_id: string, status_code: string, effective_from: string, effective_to: ?string}>  $periods  ALL the relationship's status periods starting on/before the month end, ordered by effective_from
     * @return list<array{from: string, to: string, kind: string, status_period_id: ?string, status_detail_id: ?string, status_code: ?string, derived_from_status_period_id: ?string, is_on_duty: ?bool}>
     */
    public static function segment(string $activeFrom, string $activeTo, array $periods): array
    {
        $found = [];
        $count = count($periods);

        foreach (array_values($periods) as $i => $period) {
            $explicit = MonthInterval::intersect($period['effective_from'], $period['effective_to'], $activeFrom, $activeTo);
            if ($explicit !== null) {
                $found[] = [
                    'from' => $explicit[0], 'to' => $explicit[1], 'kind' => self::EXPLICIT,
                    'status_period_id' => $period['id'], 'status_detail_id' => $period['status_detail_id'],
                    'status_code' => $period['status_code'], 'derived_from_status_period_id' => null,
                    'is_on_duty' => $period['status_code'] === BoundedEmploymentStatusPolicy::DERIVED_RETURN_CODE,
                ];
            }

            if ($period['effective_to'] !== null && BoundedEmploymentStatusPolicy::supportsEnd($period['status_code'])) {
                $next = $i + 1 < $count ? array_values($periods)[$i + 1]['effective_from'] : null;
                $derived = MonthInterval::intersect($period['effective_to'], $next, $activeFrom, $activeTo);
                if ($derived !== null) {
                    $found[] = [
                        'from' => $derived[0], 'to' => $derived[1], 'kind' => self::DERIVED_ON_DUTY,
                        'status_period_id' => null, 'status_detail_id' => null,
                        'status_code' => BoundedEmploymentStatusPolicy::DERIVED_RETURN_CODE,
                        'derived_from_status_period_id' => $period['id'], 'is_on_duty' => true,
                    ];
                }
            }
        }

        usort($found, fn (array $a, array $b) => $a['from'] <=> $b['from']);

        $segments = [];
        $cursor = $activeFrom;
        foreach ($found as $segment) {
            if ($segment['from'] > $cursor) {
                $segments[] = self::gap($cursor, $segment['from']);
            }
            $segments[] = $segment;
            $cursor = $segment['to'];
        }
        if ($cursor < $activeTo) {
            $segments[] = self::gap($cursor, $activeTo);
        }

        return $segments;
    }

    /**
     * The relationship's last EXPLICIT non-on_duty segment (the latest by start; segments never overlap, so the
     * choice is deterministic). Explicit and derived on_duty are excluded, and so is anything outside the active
     * days — in particular the terminal/ended status that begins AT the relationship end.
     *
     * @param  list<array<string, mixed>>  $segments
     * @return array<string, mixed>|null
     */
    public static function lastNonOnDuty(array $segments): ?array
    {
        $last = null;
        foreach ($segments as $segment) {
            if ($segment['kind'] === self::EXPLICIT && $segment['is_on_duty'] === false) {
                $last = $segment;
            }
        }

        return $last;
    }

    /** @return array<string, mixed> */
    private static function gap(string $from, string $to): array
    {
        return [
            'from' => $from, 'to' => $to, 'kind' => self::UNRESOLVED,
            'status_period_id' => null, 'status_detail_id' => null, 'status_code' => null,
            'derived_from_status_period_id' => null, 'is_on_duty' => null,
        ];
    }
}
