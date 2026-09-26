<?php

namespace App\Modules\HumanResources\Application\Commands;

use App\Modules\HumanResources\Domain\Exceptions\EmploymentRelationshipAlreadyEndedException;
use App\Modules\HumanResources\Domain\Exceptions\InvalidStatusPeriodDateException;
use App\Modules\HumanResources\Domain\Exceptions\UnresolvedEmploymentStatusBehaviorException;
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
     * @throws EmploymentRelationshipAlreadyEndedException|InvalidStatusPeriodDateException
     * @throws UnresolvedEmploymentStatusBehaviorException
     */
    public function handle(
        Person $person,
        EmploymentRelationship $relationship,
        EmploymentStatusDetail $statusDetail,
        string $effectiveFrom,
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

        $newFrom = Carbon::parse($effectiveFrom);

        // effective_from is set exactly once, at CreateEmploymentRelationship, and is never
        // mutated afterward by any command in this codebase — comparing against it here carries
        // no concurrency risk despite being an application-level check rather than a database
        // constraint (spec §7.3, disclosed there).
        if ($newFrom->lte($freshRelationship->effective_from)) {
            throw new InvalidStatusPeriodDateException;
        }

        $openPeriod = EmploymentStatusPeriod::query()
            ->where('employment_relationship_id', $freshRelationship->getKey())
            ->whereNull('effective_to')
            ->first();

        if ($openPeriod !== null && $newFrom->lte($openPeriod->effective_from)) {
            throw new InvalidStatusPeriodDateException;
        }

        // Resolved and validated before any write, so an unresolved behavior never leaves a
        // half-applied close-and-insert for the transaction to roll back (spec §7.6).
        $behavior = app(ResolveEmploymentStatusDetailBehaviorAsOf::class)($freshStatusDetail, $effectiveFrom);

        if ($behavior === null) {
            throw new UnresolvedEmploymentStatusBehaviorException;
        }

        if ($openPeriod !== null) {
            try {
                $openPeriod->update(['effective_to' => $effectiveFrom]);
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
            'effective_to' => null,
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
