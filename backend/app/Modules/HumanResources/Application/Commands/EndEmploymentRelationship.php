<?php

namespace App\Modules\HumanResources\Application\Commands;

use App\Modules\HumanResources\Domain\Exceptions\EmploymentRelationshipAlreadyEndedException;
use App\Modules\HumanResources\Domain\Exceptions\InvalidEndDateException;
use App\Modules\HumanResources\Domain\Exceptions\InvalidFullSecondmentEndDateException;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\EmploymentCategoryPeriod;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\EmploymentContractPeriod;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\EmploymentJobTitlePeriod;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\EmploymentRelationship;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\EmploymentSpecialtyPeriod;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\EmploymentStatusPeriod;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\PartialSecondmentPeriod;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\Person;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\ReturnIntentionPeriod;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\WorkSchedulePeriod;
use App\Modules\Platform\Infrastructure\Persistence\Postgres\PostgresErrorClassifier as Errors;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;

/**
 * The one, narrow, explicit command for ending a relationship (spec §10/§15) — S09 does not model
 * *which* detailed status caused the ending (that is S06's ref.employment_status_details/
 * _behaviors stream, deferred). `isTerminal` is the single bit of information that stream will
 * eventually own that S09's own reappointment invariant structurally needs now, so it is the only
 * bit captured here.
 *
 * Optimistic-concurrency guarded via the same scoped conditional UPDATE shape as every prior
 * module (RenameOrganizationalUnit, etc.), but with a single collapsed failure exception rather
 * than a separate StaleVersionException: ending is the *only* mutation this command ever applies
 * to an EmploymentRelationship, and it is one-way (NOT_APPLICABLE/UNKNOWN_LEGACY → KNOWN). There
 * is therefore no scenario in which the scoped UPDATE matches zero rows for a reason other than
 * "someone already ended this relationship" — a genuine version race and "already ended" are the
 * same fact here, not two distinct outcomes, so a separate StaleVersionException class would be
 * unreachable dead code for this module in S09 v1 (spec §11 — disclosed rather than left as an
 * untested branch). When isTerminal is true, also permanently marks the Person terminal in the
 * same transaction — there is no command to reverse this (spec §10).
 *
 * S15 (docs/employment-status-lifecycle-consequences-specification.md §8): this is the single
 * orchestration point for every path that ends a relationship (it is the only command that ever
 * sets end_knowledge_state = KNOWN), so after the scoped UPDATE above succeeds it also closes
 * whichever of the open child periods the relationship still has — an active S12 Full
 * Secondment (§8.1, reusing EndFullSecondment in-process, never duplicated), an active S16
 * Workplace Assignment (docs/workplace-assignment-foundation-specification.md §S16.8/§S16.10,
 * reusing EndWorkplaceAssignment in-process, never duplicated — added in the same
 * single-orchestration-point pattern this class already established), and/or the last open S10
 * Employment Status period (§8.3, inlined — no separate close command exists for it, S10 spec
 * §11) — at the exact same effectiveTo, closing S12's own disclosed §7.3 gap, S16's equivalent
 * gap, and S10's own disclosed §18 item 1 gap in one place rather than per caller. Deliberately
 * does NOT touch S11 Organizational Placement (spec §8.2, considered and rejected: no
 * disclosed-gap precedent, and ResolveActualWorkplaceForRelationship already neutralises the
 * read-time effect for an ended relationship without rewriting workplace history).
 *
 * S20 (docs/employment-category-history-foundation-specification.md §S20.11, ADR-S20-001 §4):
 * extended in place, in the same single-orchestration-point pattern, to also close the open S20
 * Employment Category period at the exact same effectiveTo — an EmploymentCategoryPeriod must
 * never extend beyond its EmploymentRelationship. Nothing is deleted or rewritten other than that
 * one effective_to.
 *
 * S21 (docs/employment-contract-foundation-specification.md §S21.11, ADR-S21-001 §8): extended in
 * the same place to keep contract history temporally coherent — a contract period's ACTUAL
 * validity is closed at the relationship's effectiveTo when it would otherwise extend beyond it
 * (including a known-term contract ended early), so history never claims a contract in force
 * after employment ended. The agreed term (contractual_effective_to) is never rewritten.
 *
 * S22 (docs/employment-job-title-history-foundation-specification.md §S22.11, ADR-S22-001 §7):
 * extended in the same place to close the open S22 Employment Job Title period at the same
 * effectiveTo, with exactly the S20 category-period rule — a job title never remains in force
 * beyond its relationship, a title that already ended is never extended, and a period that would
 * lie outside the relationship rejects the end rather than being deleted or rewritten.
 *
 * S26 (docs/employee-specialty-history-foundation-specification.md §S26.11, ADR-S26-001): the open
 * S26 Employee Specialty period is closed in the same place with exactly the S20/S22 rule.
 *
 * S29 (docs/work-schedule-foundation-specification.md §S29.10, ADR-S29-004): the open S29 Work
 * Schedule period is closed in the same place with exactly the S20/S22/S26 rule.
 *
 * S30 (docs/partial-secondment-foundation-specification.md §S30.16, ADR-S30-009): every Partial
 * Secondment still effective at the end date (open, or recorded with a later planned end) is
 * closed there; one starting on or after the end date rejects the end atomically.
 *
 * S35 (Employment Relationship End Movement Integrity): the S15/S16 handling above is REPLACED for
 * Full Secondment and Workplace Assignment by RelationshipEndMovementConsequences (one shared
 * implementation, no longer calling the user-facing End commands): a movement crossing the end date —
 * open or bounded alike — is truncated to end exactly at it; a historical one is untouched; a future
 * one (starting on or after the end) never blocks the end and is kept exactly as recorded, never
 * effective because the relationship ended; ACTIONABLE S31 follow-ups of the affected movements are
 * suppressed (RELATIONSHIP_ENDED) in the same transaction. EndFullSecondment/EndWorkplaceAssignment
 * now reject an already-ended relationship, so a surviving future row cannot be mutated afterwards.
 *
 * No explicit lockForUpdate() is added here — the caller (controller) holds the relationship row
 * lock first and the scoped UPDATE above keeps it for the rest of the transaction.
 */
final class EndEmploymentRelationship
{
    /**
     * @throws EmploymentRelationshipAlreadyEndedException|InvalidEndDateException
     * @throws InvalidFullSecondmentEndDateException
     */
    public function handle(
        Person $person,
        EmploymentRelationship $relationship,
        int $expectedVersion,
        string $effectiveTo,
        bool $isTerminal,
    ): EmploymentRelationship {
        if ($relationship->end_knowledge_state === 'KNOWN') {
            throw new EmploymentRelationshipAlreadyEndedException;
        }

        try {
            $updated = EmploymentRelationship::query()
                ->where('id', $relationship->getKey())
                ->where('version', $expectedVersion)
                ->where('end_knowledge_state', '!=', 'KNOWN')
                ->update([
                    'effective_to' => $effectiveTo,
                    'end_knowledge_state' => 'KNOWN',
                    'ended_terminally' => $isTerminal,
                    'version' => $expectedVersion + 1,
                ]);
        } catch (QueryException $e) {
            // employment_relationships_period_check (spec §9/§19): effective_to must be strictly
            // after effective_from. Not pre-validated at the controller because the comparison is
            // against this specific relationship's own effective_from, which the database
            // constraint is the single source of truth for inside this transaction.
            if (Errors::isCheckViolation($e)) {
                throw new InvalidEndDateException;
            }

            throw $e;
        }

        if ($updated === 0) {
            EmploymentRelationship::query()->where('id', $relationship->getKey())->firstOrFail();

            throw new EmploymentRelationshipAlreadyEndedException;
        }

        if ($isTerminal && ! $person->is_terminal) {
            $person->forceFill(['is_terminal' => true, 'version' => $person->version + 1])->save();
        }

        // S35: Full Secondment and Workplace Assignment (Case 1 truncate / Case 2 untouched / Case 3 kept as
        // recorded, never effective) and their S31 follow-ups — one implementation shared by every path.
        app(RelationshipEndMovementConsequences::class)->apply($relationship, $effectiveTo);
        $this->closeOpenStatusPeriodIfAny($relationship, $effectiveTo);
        $this->closeOpenEmploymentCategoryPeriodIfAny($relationship, $effectiveTo);
        $this->closeEmploymentContractValidityAtEndIfAny($relationship, $effectiveTo);
        $this->closeOpenEmploymentJobTitlePeriodIfAny($relationship, $effectiveTo);
        $this->closeOpenEmploymentSpecialtyPeriodIfAny($relationship, $effectiveTo);
        $this->closeOpenWorkSchedulePeriodIfAny($relationship, $effectiveTo);
        $this->closeEffectivePartialSecondmentsAtEnd($relationship, $effectiveTo);
        $this->closeReturnIntentionPeriodsAtEnd($relationship, $effectiveTo);

        return $relationship->refresh();
    }

    /**
     * S34: Return Intention history never extends beyond its relationship. A period in force past the
     * end date (open or bounded) is truncated to it; one starting on or after the end date would lie
     * outside the relationship and rejects the end atomically (S09's InvalidEndDateException). Periods
     * that already ended on/before the end date, and all history, stay as recorded.
     */
    private function closeReturnIntentionPeriodsAtEnd(EmploymentRelationship $relationship, string $effectiveTo): void
    {
        $affected = ReturnIntentionPeriod::query()
            ->where('employment_relationship_id', $relationship->getKey())
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>', $effectiveTo))
            ->orderBy('effective_from')
            ->get();

        foreach ($affected as $period) {
            if (Carbon::parse($effectiveTo)->lte($period->effective_from)) {
                throw new InvalidEndDateException;
            }

            try {
                $period->update(['effective_to' => $effectiveTo]);
            } catch (QueryException $e) {
                if (Errors::isCheckViolation($e)) {
                    throw new InvalidEndDateException;
                }

                throw $e;
            }
        }
    }

    /**
     * S15 spec §8.3. No separate "close status period" command exists (S10 spec §11), so this is
     * inlined exactly as RecordEmploymentStatusPeriod inlines its own close-on-transition step.
     *
     * When this command is invoked in-process by RecordEmploymentStatusPeriod (the status-
     * triggered ending path), that command has already inserted the new ended/terminal status
     * period itself, open-ended, with effective_from set to exactly the same date passed here as
     * effectiveTo (both come from the same original $effectiveFrom argument) — that period is
     * this relationship's correct, final historical status and is deliberately left open, not
     * closed: the database's own period-check constraint (effective_to > effective_from, strictly)
     * makes a zero-length close of it impossible in any case, so "equal dates → leave open" is the
     * only sound behaviour regardless of which caller produced it, not merely a special case for
     * this one path. Only a genuinely EARLIER open period (effective_from strictly before
     * effectiveTo) is the disclosed direct-route gap this method exists to close (spec §8.3); a
     * genuinely LATER open period (effective_from strictly after effectiveTo — an ended relationship
     * backdated before its own current status started) is rejected rather than silently closed.
     */
    private function closeOpenStatusPeriodIfAny(EmploymentRelationship $relationship, string $effectiveTo): void
    {
        $newTo = Carbon::parse($effectiveTo);

        // S32 (§S32.6): bounded periods generalise the S10 rule — every period still in force
        // past the end date (open or bounded) is affected, not only the open one.
        $affected = EmploymentStatusPeriod::query()
            ->where('employment_relationship_id', $relationship->getKey())
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>', $effectiveTo))
            ->orderBy('effective_from')
            ->get();

        foreach ($affected as $period) {
            // The ending status itself (open, starting exactly on the end date) is left as recorded.
            if ($period->effective_to === null && $newTo->equalTo($period->effective_from)) {
                continue;
            }

            // Reuses S09's own InvalidEndDateException (spec §14.1): a period starting on/after the
            // end date would lie outside the relationship; rejected atomically, never rewritten.
            if ($newTo->lte($period->effective_from)) {
                throw new InvalidEndDateException;
            }

            try {
                $period->update(['effective_to' => $effectiveTo]);
            } catch (QueryException $e) {
                if (Errors::isCheckViolation($e)) {
                    throw new InvalidEndDateException;
                }

                throw $e;
            }
        }
    }

    /**
     * S20 spec §S20.11 / ADR-S20-001 §4. No separate "end category" command exists (spec §S20.9),
     * so this is inlined exactly as closeOpenStatusPeriodIfAny() above inlines its own close step.
     *
     * Bounds are validated before anything is written: if ANY category period of this
     * relationship starts on or after effectiveTo, or is already closed at a date after
     * effectiveTo, the relationship end is rejected with S09's own InvalidEndDateException rather
     * than silently truncated, deleted, or left extending beyond the relationship. Unlike the
     * status period (whose ending status legitimately starts on exactly effectiveTo when
     * RecordEmploymentStatusPeriod is the caller), a category period starting on effectiveTo
     * would lie entirely outside the relationship, so "equal dates" is rejected here too. Under
     * command discipline only the open (latest) period can ever be affected; a period that
     * already ended on or before effectiveTo is left exactly as it is — never extended.
     */
    private function closeOpenEmploymentCategoryPeriodIfAny(EmploymentRelationship $relationship, string $effectiveTo): void
    {
        $extendsBeyondEnd = EmploymentCategoryPeriod::query()
            ->where('employment_relationship_id', $relationship->getKey())
            ->where(fn ($q) => $q
                ->where('effective_from', '>=', $effectiveTo)
                ->orWhere(fn ($q) => $q->whereNotNull('effective_to')->where('effective_to', '>', $effectiveTo)))
            ->exists();

        if ($extendsBeyondEnd) {
            throw new InvalidEndDateException;
        }

        $openPeriod = EmploymentCategoryPeriod::query()
            ->where('employment_relationship_id', $relationship->getKey())
            ->whereNull('effective_to')
            ->first();

        if ($openPeriod === null) {
            return;
        }

        try {
            $openPeriod->update(['effective_to' => $effectiveTo]);
        } catch (QueryException $e) {
            if (Errors::isCheckViolation($e)) {
                throw new InvalidEndDateException;
            }

            throw $e;
        }
    }

    /**
     * S21 spec §S21.11 / ADR-S21-001 §8. Bounds are validated before anything is written: a
     * contract period that STARTS on or after effectiveTo (e.g. a scheduled future renewal) would
     * lie entirely outside the relationship, so the end is rejected with S09's own
     * InvalidEndDateException rather than deleting or rewriting it — the same rule S20 applies to
     * category periods. Otherwise, any period whose actual validity is still open or extends past
     * effectiveTo (the ordinary "terminated before the agreed term" case) has its effective_to
     * moved back to exactly effectiveTo; its contractual_effective_to is preserved, so both the
     * agreed term and the real end remain on record. A period that already ended on or before
     * effectiveTo is left exactly as it is — never extended.
     */
    private function closeEmploymentContractValidityAtEndIfAny(EmploymentRelationship $relationship, string $effectiveTo): void
    {
        $startsOnOrAfterEnd = EmploymentContractPeriod::query()
            ->where('employment_relationship_id', $relationship->getKey())
            ->where('effective_from', '>=', $effectiveTo)
            ->exists();

        if ($startsOnOrAfterEnd) {
            throw new InvalidEndDateException;
        }

        $extendingPeriods = EmploymentContractPeriod::query()
            ->where('employment_relationship_id', $relationship->getKey())
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>', $effectiveTo))
            ->get();

        foreach ($extendingPeriods as $period) {
            try {
                $period->update(['effective_to' => $effectiveTo]);
            } catch (QueryException $e) {
                if (Errors::isCheckViolation($e)) {
                    throw new InvalidEndDateException;
                }

                throw $e;
            }
        }
    }

    /**
     * S22 spec §S22.11 / ADR-S22-001 §7 — identical rule to closeOpenEmploymentCategoryPeriodIfAny()
     * (S20), applied to job title periods. Bounds are validated before anything is written: a period
     * that starts on or after effectiveTo, or is already closed at a date after it (only possible
     * through imported history), would lie outside the relationship, so the end is rejected with
     * S09's own InvalidEndDateException instead of being truncated or deleted. Otherwise the open
     * period (if any) is temporally closed at exactly effectiveTo; a title that already ended on or
     * before effectiveTo is left exactly as it is — never extended.
     */
    private function closeOpenEmploymentJobTitlePeriodIfAny(EmploymentRelationship $relationship, string $effectiveTo): void
    {
        $extendsBeyondEnd = EmploymentJobTitlePeriod::query()
            ->where('employment_relationship_id', $relationship->getKey())
            ->where(fn ($q) => $q
                ->where('effective_from', '>=', $effectiveTo)
                ->orWhere(fn ($q) => $q->whereNotNull('effective_to')->where('effective_to', '>', $effectiveTo)))
            ->exists();

        if ($extendsBeyondEnd) {
            throw new InvalidEndDateException;
        }

        $openPeriod = EmploymentJobTitlePeriod::query()
            ->where('employment_relationship_id', $relationship->getKey())
            ->whereNull('effective_to')
            ->first();

        if ($openPeriod === null) {
            return;
        }

        try {
            $openPeriod->update(['effective_to' => $effectiveTo]);
        } catch (QueryException $e) {
            if (Errors::isCheckViolation($e)) {
                throw new InvalidEndDateException;
            }

            throw $e;
        }
    }

    /**
     * S26 spec §S26.11 — identical rule to closeOpenEmploymentJobTitlePeriodIfAny() (S22), applied
     * to employee specialty periods: a period lying outside the relationship rejects the end with
     * S09's InvalidEndDateException (never truncated or deleted); otherwise the open period (if
     * any) is temporally closed at exactly effectiveTo; an already-ended period is never extended.
     */
    private function closeOpenEmploymentSpecialtyPeriodIfAny(EmploymentRelationship $relationship, string $effectiveTo): void
    {
        $extendsBeyondEnd = EmploymentSpecialtyPeriod::query()
            ->where('employment_relationship_id', $relationship->getKey())
            ->where(fn ($q) => $q
                ->where('effective_from', '>=', $effectiveTo)
                ->orWhere(fn ($q) => $q->whereNotNull('effective_to')->where('effective_to', '>', $effectiveTo)))
            ->exists();

        if ($extendsBeyondEnd) {
            throw new InvalidEndDateException;
        }

        $openPeriod = EmploymentSpecialtyPeriod::query()
            ->where('employment_relationship_id', $relationship->getKey())
            ->whereNull('effective_to')
            ->first();

        if ($openPeriod === null) {
            return;
        }

        try {
            $openPeriod->update(['effective_to' => $effectiveTo]);
        } catch (QueryException $e) {
            if (Errors::isCheckViolation($e)) {
                throw new InvalidEndDateException;
            }

            throw $e;
        }
    }

    /**
     * S29 spec §S29.10 — identical rule to closeOpenEmploymentSpecialtyPeriodIfAny() (S26), applied
     * to work schedule periods: a period lying outside the relationship (including a future-dated
     * schedule starting on or after effectiveTo) rejects the end with S09's InvalidEndDateException
     * (never truncated or deleted); otherwise the open period (if any) is temporally closed at
     * exactly effectiveTo; an already-ended period is never extended. Weekday membership is never
     * touched.
     */
    private function closeOpenWorkSchedulePeriodIfAny(EmploymentRelationship $relationship, string $effectiveTo): void
    {
        $extendsBeyondEnd = WorkSchedulePeriod::query()
            ->where('employment_relationship_id', $relationship->getKey())
            ->where(fn ($q) => $q
                ->where('effective_from', '>=', $effectiveTo)
                ->orWhere(fn ($q) => $q->whereNotNull('effective_to')->where('effective_to', '>', $effectiveTo)))
            ->exists();

        if ($extendsBeyondEnd) {
            throw new InvalidEndDateException;
        }

        $openPeriod = WorkSchedulePeriod::query()
            ->where('employment_relationship_id', $relationship->getKey())
            ->whereNull('effective_to')
            ->first();

        if ($openPeriod === null) {
            return;
        }

        try {
            $openPeriod->update(['effective_to' => $effectiveTo]);
        } catch (QueryException $e) {
            if (Errors::isCheckViolation($e)) {
                throw new InvalidEndDateException;
            }

            throw $e;
        }
    }

    /**
     * S30 spec §S30.16 / ADR-S30-009. Validated before anything is written: a Partial Secondment
     * starting on or after effectiveTo would lie entirely outside the relationship, so the end is
     * rejected with S09's InvalidEndDateException rather than deleting or rewriting it. Otherwise
     * every Partial Secondment whose period is still open or extends past effectiveTo (several may,
     * with disjoint weekdays) has its effective_to moved to exactly effectiveTo — the weekday
     * membership rows are re-copied by PartialSecondmentPeriod (deferred FK). A period that already ended on or
     * before effectiveTo is left exactly as it is — never extended.
     */
    private function closeEffectivePartialSecondmentsAtEnd(EmploymentRelationship $relationship, string $effectiveTo): void
    {
        $startsOnOrAfterEnd = PartialSecondmentPeriod::query()
            ->where('employment_relationship_id', $relationship->getKey())
            ->where('effective_from', '>=', $effectiveTo)
            ->exists();

        if ($startsOnOrAfterEnd) {
            throw new InvalidEndDateException;
        }

        $extending = PartialSecondmentPeriod::query()
            ->where('employment_relationship_id', $relationship->getKey())
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>', $effectiveTo))
            ->get();

        foreach ($extending as $period) {
            try {
                $period->update(['effective_to' => $effectiveTo]);
            } catch (QueryException $e) {
                if (Errors::isCheckViolation($e)) {
                    throw new InvalidEndDateException;
                }

                throw $e;
            }
        }
    }
}
