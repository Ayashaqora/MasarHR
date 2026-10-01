<?php

namespace App\Modules\HumanResources\Domain;

use App\Modules\HumanResources\Domain\Exceptions\InconsistentDimensionHistoryException;

/**
 * Pure, deterministic interval segmentation for the S40 monthly dimension foundation
 * (docs/monthly-workforce-multi-value-dimensions-specification.md). No clock, no database.
 *
 * A dimension history (one relationship, one stream) is cut into segments of the relationship's month window
 * [from, to): every segment is half-open, intersects the window, and the segments tile it exactly. A recorded period
 * yields a RESOLVED segment; a part of the window with no recorded period yields NOT_RECORDED (a gap is never
 * filled, extended or extrapolated); a dimension that does not apply to the relationship yields one NOT_APPLICABLE
 * segment. A period history that overlaps itself, or a period recorded where the dimension does not apply, is
 * corrupt data and raises InconsistentDimensionHistoryException — it is never resolved by picking a value.
 *
 * A temporal reference mapping is resolved per sub-interval: a RESOLVED segment is cut at every date on which the
 * mapping of its value starts or ends, and each sub-interval carries RESOLVED (a mapping covers it) or UNMAPPED
 * (none does). No monthly scalar is chosen and nothing is counted.
 */
final class MonthlyDimensionSegmentation
{
    public const RESOLVED = 'RESOLVED';

    public const NOT_RECORDED = 'NOT_RECORDED';

    public const NOT_APPLICABLE = 'NOT_APPLICABLE';

    public const MAPPING_RESOLVED = 'RESOLVED';

    public const MAPPING_UNMAPPED = 'UNMAPPED';

    /**
     * @param  list<array<string, mixed>>  $periods  each with effective_from / effective_to (Y-m-d, null = open) and any payload
     * @return list<array{from: string, to: string, state: string, period: array<string, mixed>|null}>
     */
    public static function segments(string $from, string $to, array $periods, bool $applicable): array
    {
        if (! $applicable) {
            if ($periods !== []) {
                throw new InconsistentDimensionHistoryException('A period is recorded for a dimension that does not apply to the relationship.');
            }

            return [['from' => $from, 'to' => $to, 'state' => self::NOT_APPLICABLE, 'period' => null]];
        }

        $segments = [];
        foreach (self::tile($from, $to, $periods) as [$start, $end, $period]) {
            $segments[] = ['from' => $start, 'to' => $end, 'state' => $period === null ? self::NOT_RECORDED : self::RESOLVED, 'period' => $period];
        }

        return $segments;
    }

    /**
     * Cut every RESOLVED segment at the starts/ends of its value's mapping periods.
     *
     * @param  list<array{from: string, to: string, state: string, period: array<string, mixed>|null}>  $segments
     * @param  array<string, list<array<string, mixed>>>  $mappingsByValue  mapping periods keyed by the dimension value id
     * @return list<array{from: string, to: string, state: string, period: array<string, mixed>|null, mapping_state: string|null, mapping: array<string, mixed>|null}>
     */
    public static function mapped(array $segments, array $mappingsByValue, string $valueKey): array
    {
        $result = [];
        foreach ($segments as $segment) {
            if ($segment['state'] !== self::RESOLVED) {
                $result[] = $segment + ['mapping_state' => null, 'mapping' => null];

                continue;
            }

            foreach (self::tile($segment['from'], $segment['to'], $mappingsByValue[$segment['period'][$valueKey]] ?? []) as [$start, $end, $mapping]) {
                $result[] = [
                    'from' => $start, 'to' => $end, 'state' => $segment['state'], 'period' => $segment['period'],
                    'mapping_state' => $mapping === null ? self::MAPPING_UNMAPPED : self::MAPPING_RESOLVED, 'mapping' => $mapping,
                ];
            }
        }

        return $result;
    }

    /**
     * Tile [$from, $to) with the (clipped) items, null filling every part no item covers.
     *
     * @param  list<array<string, mixed>>  $items
     * @return list<array{0: string, 1: string, 2: array<string, mixed>|null}>
     */
    private static function tile(string $from, string $to, array $items): array
    {
        usort($items, fn (array $a, array $b) => [$a['effective_from'], $a['id'] ?? ''] <=> [$b['effective_from'], $b['id'] ?? '']);

        $previous = null;
        $tiles = [];
        $cursor = $from;
        foreach ($items as $item) {
            if ($previous !== null && ($previous['effective_to'] === null || $item['effective_from'] < $previous['effective_to'])) {
                throw new InconsistentDimensionHistoryException('Two periods of one temporal stream overlap; the database exclusion constraint forbids this.');
            }
            $previous = $item;

            $clip = MonthInterval::intersect($item['effective_from'], $item['effective_to'], $from, $to);
            if ($clip === null) {
                continue;
            }
            if ($clip[0] > $cursor) {
                $tiles[] = [$cursor, $clip[0], null];
            }
            $tiles[] = [$clip[0], $clip[1], $item];
            $cursor = $clip[1];
        }
        if ($cursor < $to) {
            $tiles[] = [$cursor, $to, null];
        }

        return $tiles;
    }
}
