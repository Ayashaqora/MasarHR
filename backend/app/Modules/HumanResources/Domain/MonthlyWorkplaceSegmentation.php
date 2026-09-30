<?php

namespace App\Modules\HumanResources\Domain;

/**
 * Workplace and Work Schedule interval segmentation of ONE Employment Relationship's active days inside a
 * reporting month (docs/monthly-workforce-reporting-semantics-foundation-specification.md §S37.9).
 *
 * NO competing precedence model: the month is cut at every date on which any movement stream changes, and
 * each sub-interval is resolved by the SAME single decision table S27/S30 use,
 * ActualWorkplaceAsOf::fromEffectiveFacts (RESOLVED / UNRESOLVED / AMBIGUOUS_MOVEMENT_STATE /
 * PARTIAL_ALLOCATION). Transfer is already part of placement history. Adjacent sub-intervals with an identical
 * resolved result are merged, so cut dates that change nothing leave no artificial split.
 *
 * Nothing is counted: no percentage, no allocated-day total, no assumed working day. A Work Schedule that is
 * not recorded stays NOT_RECORDED — never a default week. Pure: no database, no clock.
 */
final class MonthlyWorkplaceSegmentation
{
    /**
     * @param  list<array{id: string, organizational_unit_id: string, effective_from: string, effective_to: ?string}>  $placements
     * @param  list<array{id: string, organizational_unit_id: string, effective_from: string, effective_to: ?string}>  $secondments
     * @param  list<array{id: string, organizational_unit_id: string, effective_from: string, effective_to: ?string}>  $assignments
     * @param  list<array{id: string, organizational_unit_id: string, effective_from: string, effective_to: ?string, weekdays: list<string>}>  $partials
     * @return list<array<string, mixed>>
     */
    public static function workplace(string $activeFrom, string $activeTo, array $placements, array $secondments, array $assignments, array $partials): array
    {
        $streams = [$placements, $secondments, $assignments, $partials];
        $segments = [];

        foreach (self::cutIntervals($activeFrom, $activeTo, $streams) as [$from, $to]) {
            $fact = fn (?array $p) => $p === null ? null : ['id' => $p['id'], 'organizational_unit_id' => $p['organizational_unit_id'], 'effective_from' => $p['effective_from']];
            $coverWith = fn (array $periods) => array_values(array_filter($periods, fn (array $p) => self::covers($p, $from)));

            $resolved = ActualWorkplaceAsOf::fromEffectiveFacts(
                $fact($coverWith($placements)[0] ?? null),
                $fact($coverWith($secondments)[0] ?? null),
                $fact($coverWith($assignments)[0] ?? null),
                $coverWith($partials),
            );

            $shape = [
                'state' => $resolved->state(),
                'organizational_unit_id' => $resolved->organizationalUnitId(),
                'source' => $resolved->source(),
                'since' => $resolved->since()?->toDateString(),
                'competing_movements' => $resolved->competingMovements(),
                'partial_allocations' => $resolved->partialAllocations(),
                'underlying_organizational_unit_id' => $resolved->underlyingOrganizationalUnitId(),
            ];

            $last = count($segments) - 1;
            if ($last >= 0 && self::withoutBounds($segments[$last]) === $shape) {
                $segments[$last]['to'] = $to;

                continue;
            }

            $segments[] = ['from' => $from, 'to' => $to] + $shape;
        }

        return $segments;
    }

    /**
     * @param  list<array{id: string, effective_from: string, effective_to: ?string, weekdays: list<string>}>  $schedules
     * @return list<array{from: string, to: string, state: string, work_schedule_period_id: ?string, weekdays: list<string>}>
     */
    public static function schedule(string $activeFrom, string $activeTo, array $schedules): array
    {
        $segments = [];

        foreach (self::cutIntervals($activeFrom, $activeTo, [$schedules]) as [$from, $to]) {
            $period = array_values(array_filter($schedules, fn (array $p) => self::covers($p, $from)))[0] ?? null;

            $segments[] = $period === null
                ? ['from' => $from, 'to' => $to, 'state' => WorkScheduleAsOf::NOT_RECORDED, 'work_schedule_period_id' => null, 'weekdays' => []]
                : ['from' => $from, 'to' => $to, 'state' => WorkScheduleAsOf::RESOLVED, 'work_schedule_period_id' => $period['id'], 'weekdays' => $period['weekdays']];
        }

        return $segments;
    }

    /**
     * @param  list<list<array{effective_from: string, effective_to: ?string}>>  $streams
     * @return list<array{0: string, 1: string}>
     */
    private static function cutIntervals(string $activeFrom, string $activeTo, array $streams): array
    {
        $cuts = [$activeFrom, $activeTo];
        foreach ($streams as $periods) {
            foreach ($periods as $period) {
                foreach ([$period['effective_from'], $period['effective_to']] as $date) {
                    if ($date !== null && $date > $activeFrom && $date < $activeTo) {
                        $cuts[] = $date;
                    }
                }
            }
        }
        $cuts = array_values(array_unique($cuts));
        sort($cuts);

        $intervals = [];
        for ($i = 0; $i + 1 < count($cuts); $i++) {
            $intervals[] = [$cuts[$i], $cuts[$i + 1]];
        }

        return $intervals;
    }

    /** @param array{effective_from: string, effective_to: ?string} $period */
    private static function covers(array $period, string $date): bool
    {
        return $period['effective_from'] <= $date && ($period['effective_to'] === null || $date < $period['effective_to']);
    }

    /** @param array<string, mixed> $segment */
    private static function withoutBounds(array $segment): array
    {
        unset($segment['from'], $segment['to']);

        return $segment;
    }
}
