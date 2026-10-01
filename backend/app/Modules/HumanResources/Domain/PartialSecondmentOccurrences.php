<?php

namespace App\Modules\HumanResources\Domain;

use DateTimeImmutable;
use DateTimeZone;

/**
 * R2 (docs/administrative-report-foundation-specification.md §S42.7): which configured weekdays of a Partial Secondment actually
 * occur inside Report-interval INTERSECT Movement-effective-period, on half-open DATE intervals [from, to). Weekdays are an
 * ALLOCATION, never attendance: nothing is counted, only the weekday codes that occur at least once are returned, and an empty
 * result means the destination has zero applicable scheduled occurrences and must not create a workplace occurrence. Pure.
 */
final class PartialSecondmentOccurrences
{
    /**
     * @param  string  $from  the start of the report interval being evaluated (inclusive)
     * @param  string  $to  its end (exclusive)
     * @param  list<string>  $weekdays  configured weekday codes (SUNDAY … SATURDAY), in their configured order
     * @return list<string> the configured weekdays that occur at least once in the intersection, in configured order
     */
    public static function applicableWeekdays(string $from, string $to, string $movementFrom, ?string $movementTo, array $weekdays): array
    {
        $window = MonthInterval::intersect($movementFrom, $movementTo, $from, $to);
        if ($window === null || $weekdays === []) {
            return [];
        }

        $occurring = [];
        $day = new DateTimeImmutable($window[0], new DateTimeZone('UTC'));
        $end = new DateTimeImmutable($window[1], new DateTimeZone('UTC'));
        while ($day < $end) {
            $occurring[strtoupper($day->format('l'))] = true;
            if (count($occurring) === 7) {
                break;
            }
            $day = $day->modify('+1 day');
        }

        return array_values(array_filter($weekdays, fn (string $code) => isset($occurring[$code])));
    }
}
