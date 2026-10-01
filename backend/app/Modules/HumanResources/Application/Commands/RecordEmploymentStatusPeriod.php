<?php

namespace App\Modules\HumanResources\Application\Commands;

use App\Modules\HumanResources\Domain\BoundedEmploymentStatusPolicy;
use App\Modules\HumanResources\Domain\Exceptions\EmploymentRelationshipAlreadyEndedException;
use App\Modules\HumanResources\Domain\Exceptions\InvalidStatusPeriodDateException;
use App\Modules\HumanResources\Domain\Exceptions\InvalidStatusPeriodEndException;
use App\Modules\HumanResources\Domain\Exceptions\InvalidTravelPayStatusException;
use App\Modules\HumanResources\Domain\Exceptions\RetiredEmploymentStatusCodeException;
use App\Modules\HumanResources\Domain\Exceptions\UnresolvedEmploymentStatusBehaviorException;
use App\Modules\HumanResources\Domain\RetiredEmploymentStatusCodes;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\EmploymentRelationship;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\EmploymentStatusPeriod;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\Person;
use App\Modules\Platform\Infrastructure\Persistence\Postgres\PostgresErrorClassifier as Errors;
use App\Modules\Reference\Application\Queries\ResolveEmploymentStatusDetailBehaviorAsOf;
use App\Modules\Reference\Infrastructure\Persistence\Eloquent\EmploymentStatusDetail;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;

/**
 * Records one employment-status transition for an Employment Relationship and wires its already-
 * approved consequences (docs/employment-status-history-foundation-specification.md §7): closing
 * the currently-open status period (if any) at exactly the new period's effective_from, then, if
 * the resolved S06 behavior is relationship-ending or terminal, ending the Employment Relationship
 * itself via S09's own EndEmploymentRelationship — called in-process, inside this same transaction,
 * never through a second AuditedCommandExecutor::run() (which would double-audit and attempt a
 * transaction the executor does not support nesting).
 *
 * The EmploymentRelationship is re-fetched fresh with lockForUpdate() as the first statement here
 * — never trusted from whatever the caller passed in — exactly mirroring
 * CreateEmploymentRelationship's own established discipline (spec §7.1/§17). This also serializes
 * this command against a concurrent EndEmploymentRelationship or RecordEmploymentStatusPeriod call
 * on the same relationship (spec §17).
 */
final class RecordEmploymentStatusPeriod
{
    /**
     * S32 (docs/bounded-temporary-employment-status-lifecycle-specification.md §S32.3): the optional
     * $effectiveTo bounds allow-listed temporary codes only; validation order is documented there.
     *
     * @throws EmploymentRelationshipAlreadyEndedException|InvalidStatusPeriodDateException|InvalidStatusPeriodEndException
     * @throws RetiredEmploymentStatusCodeException
     * @throws UnresolvedEmploymentStatusBehaviorException
     */
    public function handle(
        Person $person,
        EmploymentRelationship $relationship,
        EmploymentStatusDetail $statusDetail,
        string $effectiveFrom,
        ?string $effectiveTo = null,
        ?string $travelPayStatus = null,
    ): EmploymentStatusPeriod {
        $freshRelationship = EmploymentRelationship::query()
            ->where('id', $relationship->getKey())
            ->lockForUpdate()
            ->firstOrFail();

        if ($freshRelationship->end_knowledge_state === 'KNOWN') {
            throw new EmploymentRelationshipAlreadyEndedException;
        }

        $freshStatusDetail = EmploymentStatusDetail::query()
            ->where('id', $statusDetail->getKey())
            ->firstOrFail();

        $code = (string) $freshStatusDetail->code;

        // S41 (R1-D36): the pay indicator is meaningful only for `traveling`; NULL (omitted) is always valid. A CHECK cannot read
        // the status code from ref.employment_status_details, so applicability is enforced here.
        if ($travelPayStatus !== null && ($code !== 'traveling' || ! in_array($travelPayStatus, ['PAID', 'UNPAID'], true))) {
            throw new InvalidTravelPayStatusException;
        }

        // S34: the two legacy Return Intention codes are no longer employment statuses. This code-level
        // guard is authoritative — reactivating the catalog row does not bypass it.
        if (RetiredEmploymentStatusCodes::isRetired($code)) {
            throw new RetiredEmploymentStatusCodeException($code);
        }

        if ($effectiveTo !== null && ! BoundedEmploymentStatusPolicy::supportsEnd($code)) {
            throw new InvalidStatusPeriodEndException("effective_to is not supported for status '{$code}'.");
        }

        if ($effectiveTo === null && BoundedEmploymentStatusPolicy::requiresEnd($code)) {
            throw new InvalidStatusPeriodEndException("effective_to is required for status '{$code}'.");
        }

        $newFrom = Carbon::parse($effectiveFrom);

        if ($effectiveTo !== null && ! Carbon::parse($effectiveTo)->gt($newFrom)) {
            throw new InvalidStatusPeriodEndException('effective_to must be strictly after effective_from.');
        }

        // effective_from is set exactly once, at CreateEmploymentRelationship, and is never
        // mutated afterward by any command in this codebase — comparing against it here carries
        // no concurrency risk despite being an application-level check rather than a database
        // constraint (spec §7.3, disclosed there).
        if ($newFrom->lte($freshRelationship->effective_from)) {
            throw new InvalidStatusPeriodDateException;
        }

        // S32 R2: recorded status history starting on/after the new start is never silently
        // rewritten — reject atomically.
        $hasLaterOrEqual = EmploymentStatusPeriod::query()
            ->where('employment_relationship_id', $freshRelationship->getKey())
            ->where('effective_from', '>=', $effectiveFrom)
            ->exists();

        if ($hasLaterOrEqual) {
            throw new InvalidStatusPeriodDateException;
        }

        // R3: the period (open or bounded) covering the new start is truncated at it. Because R2
        // holds, that period is the latest one. A bounded new period strictly inside a bounded
        // covering one would need a split (a rewrite) — rejected.
        $coveringPeriod = EmploymentStatusPeriod::query()
            ->where('employment_relationship_id', $freshRelationship->getKey())
            ->where('effective_from', '<', $effectiveFrom)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>', $effectiveFrom))
            ->first();

        if ($coveringPeriod !== null && $coveringPeriod->effective_to !== null && $effectiveTo !== null
            && Carbon::parse($effectiveTo)->lt($coveringPeriod->effective_to)) {
            throw new InvalidStatusPeriodEndException('effective_to would end inside an already recorded bounded status period; recorded history is not rewritten.');
        }

        // Resolved and validated before any write, so an unresolved behavior never leaves a
        // half-applied close-and-insert for the transaction to roll back (spec §7.6).
        $behavior = app(ResolveEmploymentStatusDetailBehaviorAsOf::class)($freshStatusDetail, $effectiveFrom);

        if ($behavior === null) {
            throw new UnresolvedEmploymentStatusBehaviorException;
        }

        if ($coveringPeriod !== null) {
            try {
                $coveringPeriod->update(['effective_to' => $effectiveFrom]);
            } catch (QueryException $e) {
                if (Errors::isCheckViolation($e)) {
                    throw new InvalidStatusPeriodDateException;
                }

                throw $e;
            }
        }

        $period = new EmploymentStatusPeriod([
            'employment_relationship_id' => $freshRelationship->getKey(),
            'status_detail_id' => $freshStatusDetail->getKey(),
            'effective_from' => $effectiveFrom,
            'effective_to' => $effectiveTo,
            'travel_pay_status' => $travelPayStatus,
        ]);

        try {
            $period->save();
        } catch (QueryException $e) {
            if (Errors::isExclusionViolation($e) || Errors::isCheckViolation($e)) {
                throw new InvalidStatusPeriodDateException;
            }

            throw $e;
        }

        if ($behavior->is_relationship_ending || $behavior->is_terminal) {
            app(EndEmploymentRelationship::class)->handle(
                $person,
                $freshRelationship,
                $freshRelationship->version,
                $effectiveFrom,
                (bool) $behavior->is_terminal,
            );
        }

        return $period->refresh();
    }
}
