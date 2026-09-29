<?php

namespace App\Modules\HumanResources\Application\Queries;

use App\Modules\HumanResources\Domain\ActualWorkplaceAsOf;
use App\Modules\HumanResources\Domain\WeekdayActualWorkplaceAsOf;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\EmploymentRelationship;
use App\Modules\Reference\Infrastructure\Persistence\Eloquent\Weekday;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * Weekday-aware actual workplace (docs/partial-secondment-foundation-specification.md §S30.17,
 * ADR-S30-011): relationship + explicit business date + weekday → actual workplace. The weekday
 * defaults to the date's own ISO weekday; an explicit code asks "under the arrangement effective on
 * this date, where does the employee work on this weekday?" (the question a report needs).
 *
 * Built on the two existing as-of readers, never a second decision table:
 *  1. S27's date-only ResolveActualWorkplaceForRelationshipAsOf — UNRESOLVED and
 *     AMBIGUOUS_MOVEMENT_STATE pass through unchanged;
 *  2. S29's ResolveWorkScheduleForRelationshipAsOf — a RESOLVED schedule without the weekday →
 *     NOT_SCHEDULED; a NOT_RECORDED schedule is never read as a default week;
 *  3. PARTIAL_ALLOCATION: the weekday allocated to a Partial Secondment resolves to its
 *     destination; an unallocated scheduled weekday resolves to the underlying workplace (the
 *     effective placement). A Workplace Assignment is never a hidden layer under a Partial
 *     Secondment (ADR-S30-007 rule 3 — they cannot be effective together; if legacy data has both,
 *     step 1 already reports AMBIGUOUS_MOVEMENT_STATE);
 *  4. RESOLVED date-only results (placement / full secondment / assignment) apply to every
 *     scheduled weekday.
 */
final class ResolveWeekdayActualWorkplaceAsOf
{
    public function __construct(
        private readonly ResolveActualWorkplaceForRelationshipAsOf $dateOnly,
        private readonly ResolveWorkScheduleForRelationshipAsOf $schedule,
    ) {}

    public function __invoke(EmploymentRelationship $relationship, string|Carbon $date, ?string $weekdayCode = null): WeekdayActualWorkplaceAsOf
    {
        $carbon = $date instanceof Carbon ? $date->copy() : Carbon::parse($date);
        $weekday = $weekdayCode ?? Weekday::CODES[$carbon->dayOfWeekIso - 1];

        if (! in_array($weekday, Weekday::CODES, true)) {
            throw new InvalidArgumentException('weekday must be one of MONDAY … SUNDAY.');
        }

        $workplace = ($this->dateOnly)($relationship, $carbon->toDateString());
        $schedule = ($this->schedule)($relationship, $carbon->toDateString());
        $scheduleState = $schedule->state();

        if ($workplace->state() === ActualWorkplaceAsOf::UNRESOLVED) {
            return WeekdayActualWorkplaceAsOf::unresolved($weekday, $scheduleState);
        }

        if ($workplace->isAmbiguous()) {
            return WeekdayActualWorkplaceAsOf::ambiguous($weekday, $scheduleState, $workplace->competingMovements());
        }

        if ($schedule->isRecorded() && ! $schedule->isScheduledOn($weekday)) {
            return WeekdayActualWorkplaceAsOf::notScheduled($weekday, $scheduleState);
        }

        if ($workplace->isPartialAllocation()) {
            $matches = array_values(array_filter(
                $workplace->partialAllocations(),
                fn (array $allocation) => in_array($weekday, $allocation['weekdays'], true),
            ));

            if (count($matches) > 1) {
                return WeekdayActualWorkplaceAsOf::ambiguous($weekday, $scheduleState, array_map(fn (array $m) => [
                    'source' => 'partial_secondment', 'period_id' => $m['period_id'],
                    'organizational_unit_id' => $m['organizational_unit_id'], 'effective_from' => $m['effective_from'],
                ], $matches));
            }

            if (count($matches) === 1) {
                return WeekdayActualWorkplaceAsOf::resolved($weekday, $scheduleState, $matches[0]['organizational_unit_id'], 'partial_secondment', $matches[0]['period_id']);
            }

            $underlying = $workplace->underlyingOrganizationalUnitId();

            return $underlying === null
                ? WeekdayActualWorkplaceAsOf::unresolved($weekday, $scheduleState)
                : WeekdayActualWorkplaceAsOf::resolved($weekday, $scheduleState, $underlying, 'placement');
        }

        return WeekdayActualWorkplaceAsOf::resolved($weekday, $scheduleState, $workplace->organizationalUnitId(), $workplace->source());
    }
}
