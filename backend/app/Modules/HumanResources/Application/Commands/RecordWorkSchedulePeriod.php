<?php

namespace App\Modules\HumanResources\Application\Commands;

use App\Modules\HumanResources\Domain\Exceptions\EmploymentRelationshipAlreadyEndedException;
use App\Modules\HumanResources\Domain\Exceptions\InvalidWorkSchedulePeriodDateException;
use App\Modules\HumanResources\Domain\Exceptions\InvalidWorkScheduleWeekdaysException;
use App\Modules\HumanResources\Domain\Exceptions\WorkScheduleChangeInvalidatesPartialSecondmentException;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\EmploymentRelationship;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\WorkSchedulePeriod;
use App\Modules\Platform\Infrastructure\Persistence\Postgres\PostgresErrorClassifier as Errors;
use App\Modules\Reference\Infrastructure\Persistence\Eloquent\Weekday;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Records one Work Schedule period — the first known schedule or a later schedule change — for an
 * Employment Relationship (docs/work-schedule-foundation-specification.md §S29.8/§S29.9,
 * ADR-S29-001…005). Exactly the S20/S22/S26 auto-close-on-insert discipline: when the latest period
 * is still open it is TEMPORALLY CLOSED at the new effective_from (identity, weekdays and
 * effective_from preserved), then the new period and its weekday membership are inserted. There is
 * no same-value special case: the same weekday set at a later valid date still creates a new period
 * (the effective event is itself history). A backdated start on or before the latest recorded
 * period's start is rejected — never a multi-period rewrite.
 *
 * Order (ADR-S29-005 extension point): lock the relationship → validate the relationship, the date
 * and the weekday selection → [future: validate dependent Partial Secondment weekday allocations
 * against the resulting schedule timeline, still before any write] → close the previous period →
 * insert. Every validation completes before the first mutation, and the whole command runs in one
 * transaction, so a future allocation validator slots in without restructuring the write path.
 */
final class RecordWorkSchedulePeriod
{
    public function __construct(private readonly PartialSecondmentAllocationRules $allocationRules) {}

    /**
     * @param  list<string>  $weekdayCodes
     *
     * @throws EmploymentRelationshipAlreadyEndedException|InvalidWorkSchedulePeriodDateException|InvalidWorkScheduleWeekdaysException
     * @throws WorkScheduleChangeInvalidatesPartialSecondmentException
     */
    public function handle(EmploymentRelationship $relationship, string $effectiveFrom, array $weekdayCodes): WorkSchedulePeriod
    {
        return DB::transaction(fn (): WorkSchedulePeriod => $this->record($relationship, $effectiveFrom, $weekdayCodes));
    }

    /** @param list<string> $weekdayCodes */
    private function record(EmploymentRelationship $relationship, string $effectiveFrom, array $weekdayCodes): WorkSchedulePeriod
    {
        $freshRelationship = EmploymentRelationship::query()
            ->where('id', $relationship->getKey())
            ->lockForUpdate()
            ->firstOrFail();

        if ($freshRelationship->end_knowledge_state === 'KNOWN') {
            throw new EmploymentRelationshipAlreadyEndedException;
        }

        $weekdayIds = $this->resolveWeekdays($weekdayCodes);

        $newFrom = Carbon::parse($effectiveFrom);

        // A schedule MAY start on the relationship's own effective_from but never before it (the
        // S22/S26 rule; effective_from is immutable after CreateEmploymentRelationship).
        if ($newFrom->lt($freshRelationship->effective_from)) {
            throw new InvalidWorkSchedulePeriodDateException;
        }

        // Compared against the LATEST recorded period (open or closed, including a future one), so
        // a backdated period can never be inserted before, or on top of, later history.
        $latestPeriod = WorkSchedulePeriod::query()
            ->where('employment_relationship_id', $freshRelationship->getKey())
            ->orderByDesc('effective_from')
            ->first();

        if ($latestPeriod !== null && $newFrom->lte($latestPeriod->effective_from)) {
            throw new InvalidWorkSchedulePeriodDateException;
        }

        // ADR-S29-005 extension point, connected by S30 (ADR-S30-010,
        // docs/partial-secondment-foundation-specification.md §S30.14): every Partial Secondment
        // effective on or after the new start must keep all its weekdays in the new schedule —
        // after every S29 validation, before the first write below. Rejects atomically; nothing
        // is repaired.
        $this->allocationRules->assertScheduleChangeKeepsAllocations($freshRelationship->getKey(), $newFrom->toDateString(), array_values($weekdayCodes));

        if ($latestPeriod !== null && $latestPeriod->effective_to === null) {
            try {
                $latestPeriod->update(['effective_to' => $newFrom->toDateString()]);
            } catch (QueryException $e) {
                if (Errors::isCheckViolation($e)) {
                    throw new InvalidWorkSchedulePeriodDateException;
                }

                throw $e;
            }
        }

        $period = new WorkSchedulePeriod([
            'employment_relationship_id' => $freshRelationship->getKey(),
            'effective_from' => $newFrom->toDateString(),
            'effective_to' => null,
        ]);

        try {
            $period->save();
        } catch (QueryException $e) {
            if (Errors::isExclusionViolation($e) || Errors::isCheckViolation($e)) {
                throw new InvalidWorkSchedulePeriodDateException;
            }

            throw $e;
        }

        $period->weekdays()->attach($weekdayIds);

        return $period->refresh()->load('weekdays');
    }

    /**
     * @param  list<mixed>  $weekdayCodes
     * @return list<string> weekday ids
     */
    private function resolveWeekdays(array $weekdayCodes): array
    {
        if ($weekdayCodes === []) {
            throw new InvalidWorkScheduleWeekdaysException('weekdays must name at least one weekday.');
        }

        foreach ($weekdayCodes as $code) {
            if (! is_string($code) || ! in_array($code, Weekday::CODES, true)) {
                throw new InvalidWorkScheduleWeekdaysException('weekdays may only contain MONDAY, TUESDAY, WEDNESDAY, THURSDAY, FRIDAY, SATURDAY or SUNDAY.');
            }
        }

        if (count(array_unique($weekdayCodes)) !== count($weekdayCodes)) {
            throw new InvalidWorkScheduleWeekdaysException('weekdays must not contain the same weekday twice.');
        }

        $ids = Weekday::query()->whereIn('code', $weekdayCodes)->pluck('id', 'code');

        if ($ids->count() !== count($weekdayCodes)) {
            throw new InvalidWorkScheduleWeekdaysException;
        }

        return $ids->values()->all();
    }
}
