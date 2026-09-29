<?php

namespace App\Modules\HumanResources\Application\Commands;

use App\Modules\HumanResources\Domain\Exceptions\PartialSecondmentOutsideWorkScheduleException;
use App\Modules\HumanResources\Domain\Exceptions\WorkScheduleChangeInvalidatesPartialSecondmentException;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\PartialSecondmentPeriod;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\WorkSchedulePeriod;
use App\Modules\Reference\Infrastructure\Persistence\Eloquent\Weekday;
use RuntimeException;

/**
 * The Work Schedule dependency of Partial Secondment weekday allocation
 * (docs/partial-secondment-foundation-specification.md §S30.9/§S30.14, ADR-S30-003/004/010) —
 * one internal rule set shared by the two write paths that can break it:
 *
 *  - RecordPartialSecondmentPeriod (a new allocation must lie inside the recorded schedule), and
 *  - RecordWorkSchedulePeriod at its ADR-S29-005 extension point (a new schedule must keep every
 *    weekday still allocated from its start date on).
 *
 * A NOT_RECORDED schedule is never read as a default week: any date of a Partial Secondment not
 * covered by a recorded schedule period rejects it. Pure validation — nothing is ever written, and
 * nothing is repaired (no weekday is added to a schedule, no allocation is changed or ended).
 * Callers hold the EmploymentRelationship row lock and run inside their transaction.
 */
final class PartialSecondmentAllocationRules
{
    /**
     * Resolves stable weekday codes to ref.weekdays ids, rejecting an empty selection, an unknown
     * or non-canonical code (a localized label, lowercase, a number) and a duplicate.
     *
     * @param  list<mixed>  $weekdayCodes
     * @param  callable(string): RuntimeException  $invalid
     * @return array<string, string> code => weekday id
     */
    public function resolveWeekdays(array $weekdayCodes, callable $invalid): array
    {
        if ($weekdayCodes === []) {
            throw $invalid('weekdays must name at least one weekday.');
        }

        foreach ($weekdayCodes as $code) {
            if (! is_string($code) || ! in_array($code, Weekday::CODES, true)) {
                throw $invalid('weekdays may only contain MONDAY, TUESDAY, WEDNESDAY, THURSDAY, FRIDAY, SATURDAY or SUNDAY.');
            }
        }

        if (count(array_unique($weekdayCodes)) !== count($weekdayCodes)) {
            throw $invalid('weekdays must not contain the same weekday twice.');
        }

        $ids = Weekday::query()->whereIn('code', $weekdayCodes)->pluck('id', 'code')->all();

        if (count($ids) !== count($weekdayCodes)) {
            throw $invalid('weekdays must be a non-empty list of distinct weekday codes (MONDAY … SUNDAY).');
        }

        return $ids;
    }

    /**
     * ADR-S30-004: every date of [$from, $to) must be covered by a recorded Work Schedule period
     * whose weekdays include every allocated weekday. $to null = open-ended (the schedule must be
     * open-ended too). A gap anywhere is NOT_RECORDED and rejects.
     *
     * @param  list<string>  $allocatedCodes
     */
    public function assertCoveredByWorkSchedule(string $relationshipId, string $from, ?string $to, array $allocatedCodes): void
    {
        $schedules = WorkSchedulePeriod::query()
            ->with('weekdays')
            ->where('employment_relationship_id', $relationshipId)
            ->when($to !== null, fn ($q) => $q->where('effective_from', '<', $to))
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>', $from))
            ->orderBy('effective_from')
            ->get();

        $cursor = $from; // first date not yet proven covered; null = covered to infinity

        foreach ($schedules as $schedule) {
            if ($schedule->effective_from->toDateString() > $cursor) {
                throw new PartialSecondmentOutsideWorkScheduleException('The work schedule is not recorded for part of this period; a missing schedule is never treated as a default week.');
            }

            $missing = array_diff($allocatedCodes, $schedule->weekdayCodes());

            if ($missing !== []) {
                throw new PartialSecondmentOutsideWorkScheduleException('The work schedule effective from '.$schedule->effective_from->toDateString().' does not include: '.implode(', ', array_values($missing)).'.');
            }

            $cursor = $schedule->effective_to?->toDateString();

            if ($cursor === null || ($to !== null && $cursor >= $to)) {
                return;
            }
        }

        throw new PartialSecondmentOutsideWorkScheduleException('The work schedule is not recorded for part of this period; a missing schedule is never treated as a default week.');
    }

    /**
     * ADR-S30-010 (the ADR-S29-005 extension point): a Work Schedule recorded from $newFrom with
     * $newWeekdayCodes becomes the schedule for every date on and after $newFrom, so every Partial
     * Secondment effective on any such date must allocate only weekdays in the new set.
     *
     * @param  list<string>  $newWeekdayCodes
     */
    public function assertScheduleChangeKeepsAllocations(string $relationshipId, string $newFrom, array $newWeekdayCodes): void
    {
        $affected = PartialSecondmentPeriod::query()
            ->with('weekdays')
            ->where('employment_relationship_id', $relationshipId)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>', $newFrom))
            ->orderBy('effective_from')
            ->get();

        foreach ($affected as $partial) {
            $removed = array_diff($partial->weekdayCodes(), $newWeekdayCodes);

            if ($removed !== []) {
                throw new WorkScheduleChangeInvalidatesPartialSecondmentException('The new work schedule would remove '.implode(', ', array_values($removed)).', still allocated by a partial secondment effective from '.$partial->effective_from->toDateString().'.');
            }
        }
    }
}
