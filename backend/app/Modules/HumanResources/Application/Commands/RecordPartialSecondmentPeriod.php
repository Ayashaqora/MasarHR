<?php

namespace App\Modules\HumanResources\Application\Commands;

use App\Modules\HumanResources\Domain\Exceptions\ActiveFullSecondmentAlreadyExistsException;
use App\Modules\HumanResources\Domain\Exceptions\ActiveWorkplaceAssignmentAlreadyExistsException;
use App\Modules\HumanResources\Domain\Exceptions\EmploymentRelationshipAlreadyEndedException;
use App\Modules\HumanResources\Domain\Exceptions\InvalidPartialSecondmentPeriodDateException;
use App\Modules\HumanResources\Domain\Exceptions\InvalidPartialSecondmentWeekdaysException;
use App\Modules\HumanResources\Domain\Exceptions\PartialSecondmentOutsideWorkScheduleException;
use App\Modules\HumanResources\Domain\Exceptions\PartialSecondmentWeekdayConflictException;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\EmploymentRelationship;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\FullSecondmentPeriod;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\PartialSecondmentPeriod;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\WorkplaceAssignmentPeriod;
use App\Modules\Organization\Infrastructure\Persistence\Eloquent\OrganizationalUnit;
use App\Modules\Platform\Infrastructure\Persistence\Postgres\PostgresErrorClassifier as Errors;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Records one Partial Secondment period for an Employment Relationship
 * (docs/partial-secondment-foundation-specification.md §S30.8, ADR-S30-001…007): the employee
 * works at $destination on the allocated weekdays during [effective_from, effective_to), where
 * effective_to may be null (open-ended). Explicit record command only — no generic update.
 *
 * One transaction, every validation before the first write:
 *  1. lock the relationship row (the S10–S29 discipline); a KNOWN end → 409;
 *  2. the weekday allocation (non-empty, canonical, distinct — ADR-S30-003);
 *  3. dates: strictly after the relationship's own start (the S12/S16 movement rule), and
 *     effective_to after effective_from (ADR-S30-002);
 *  4. the recorded Work Schedule covers every date of the period and contains every allocated
 *     weekday — NOT_RECORDED rejects, never a default week (ADR-S30-004);
 *  5. no Full Secondment overlaps the period in time (ADR-S30-006: reject, never supersede);
 *  6. no other Partial Secondment overlaps it in time AND shares a weekday (ADR-S30-005; disjoint
 *     weekdays coexist; nothing is truncated to make room);
 *  7. no Workplace Assignment starts inside the period (ADR-S30-007 rule 4 — later history is
 *     never rewritten);
 * then the one mutation of another stream — the Workplace Assignment EFFECTIVE at effective_from,
 * if any, is truncated at it (ADR-S30-007 rule 2, via the S28 helper) — then the insert of the
 * period and of its weekday membership rows (whose PostgreSQL EXCLUDE constraint is the backstop
 * for step 6).
 */
final class RecordPartialSecondmentPeriod
{
    public function __construct(
        private readonly PartialSecondmentAllocationRules $rules,
        private readonly SupersedeTemporaryWorkplaceMovement $supersession,
    ) {}

    /**
     * @param  list<string>  $weekdayCodes
     *
     * @throws EmploymentRelationshipAlreadyEndedException|InvalidPartialSecondmentWeekdaysException|InvalidPartialSecondmentPeriodDateException
     * @throws PartialSecondmentOutsideWorkScheduleException|ActiveFullSecondmentAlreadyExistsException
     * @throws PartialSecondmentWeekdayConflictException|ActiveWorkplaceAssignmentAlreadyExistsException
     */
    public function handle(
        EmploymentRelationship $relationship,
        OrganizationalUnit $destination,
        string $effectiveFrom,
        ?string $effectiveTo,
        array $weekdayCodes,
    ): PartialSecondmentPeriod {
        return DB::transaction(fn (): PartialSecondmentPeriod => $this->record($relationship, $destination, $effectiveFrom, $effectiveTo, $weekdayCodes));
    }

    /** @param list<string> $weekdayCodes */
    private function record(
        EmploymentRelationship $relationship,
        OrganizationalUnit $destination,
        string $effectiveFrom,
        ?string $effectiveTo,
        array $weekdayCodes,
    ): PartialSecondmentPeriod {
        $freshRelationship = EmploymentRelationship::query()
            ->where('id', $relationship->getKey())
            ->lockForUpdate()
            ->firstOrFail();

        if ($freshRelationship->end_knowledge_state === 'KNOWN') {
            throw new EmploymentRelationshipAlreadyEndedException;
        }

        $freshDestination = OrganizationalUnit::query()->where('id', $destination->getKey())->firstOrFail();

        $weekdayIds = $this->rules->resolveWeekdays($weekdayCodes, fn (string $message) => new InvalidPartialSecondmentWeekdaysException($message));
        $codes = array_keys($weekdayIds);

        $from = Carbon::parse($effectiveFrom)->toDateString();
        $to = $effectiveTo === null ? null : Carbon::parse($effectiveTo)->toDateString();

        if ($from <= $freshRelationship->effective_from->toDateString()) {
            throw new InvalidPartialSecondmentPeriodDateException('effective_from', 'effective_from must be after the employment relationship start.');
        }

        if ($to !== null && $to <= $from) {
            throw new InvalidPartialSecondmentPeriodDateException('effective_to', 'effective_to must be after effective_from.');
        }

        $relationshipId = $freshRelationship->getKey();

        $this->rules->assertCoveredByWorkSchedule($relationshipId, $from, $to, $codes);

        if ($this->overlapping(FullSecondmentPeriod::query(), $relationshipId, $from, $to)->exists()) {
            throw new ActiveFullSecondmentAlreadyExistsException;
        }

        $sharedWeekday = $this->overlapping(PartialSecondmentPeriod::query(), $relationshipId, $from, $to)
            ->whereHas('weekdays', fn ($q) => $q->whereIn('code', $codes))
            ->exists();

        if ($sharedWeekday) {
            throw new PartialSecondmentWeekdayConflictException;
        }

        $laterAssignment = WorkplaceAssignmentPeriod::query()
            ->where('employment_relationship_id', $relationshipId)
            ->where('effective_from', '>=', $from)
            ->when($to !== null, fn ($q) => $q->where('effective_from', '<', $to))
            ->exists();

        if ($laterAssignment) {
            throw new ActiveWorkplaceAssignmentAlreadyExistsException;
        }

        // ADR-S30-007 rule 2: the assignment effective at effective_from ends exactly there.
        $assignment = $this->supersession->effectiveAt(WorkplaceAssignmentPeriod::class, $relationshipId, $from);

        if ($assignment !== null) {
            $this->supersession->truncate($assignment, $from, fn () => new ActiveWorkplaceAssignmentAlreadyExistsException);
        }

        $period = new PartialSecondmentPeriod([
            'employment_relationship_id' => $relationshipId,
            'organizational_unit_id' => $freshDestination->getKey(),
            'effective_from' => $from,
            'effective_to' => $to,
        ]);

        try {
            $period->save();

            // Membership rows copy the owner and daterange from the parent row itself (verified at
            // commit by the deferred composite FK), so they can never disagree with it.
            DB::insert(
                'insert into hr.partial_secondment_period_weekdays (partial_secondment_period_id, weekday_id, employment_relationship_id, period)
                 select p.id, w.id, p.employment_relationship_id, p.period
                 from hr.partial_secondment_periods p cross join ref.weekdays w
                 where p.id = ? and w.id in ('.implode(',', array_fill(0, count($weekdayIds), '?')).')',
                [$period->getKey(), ...array_values($weekdayIds)],
            );
        } catch (QueryException $e) {
            if (Errors::isExclusionViolation($e)) {
                throw new PartialSecondmentWeekdayConflictException;
            }

            if (Errors::isCheckViolation($e)) {
                throw new InvalidPartialSecondmentPeriodDateException('effective_to', 'effective_to must be after effective_from.');
            }

            throw $e;
        }

        return $period->refresh()->load('weekdays');
    }

    /** Periods of the relationship overlapping [$from, $to) — $to null = open-ended. */
    private function overlapping(Builder $query, string $relationshipId, string $from, ?string $to): Builder
    {
        return $query
            ->where('employment_relationship_id', $relationshipId)
            ->when($to !== null, fn ($q) => $q->where('effective_from', '<', $to))
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>', $from));
    }
}
