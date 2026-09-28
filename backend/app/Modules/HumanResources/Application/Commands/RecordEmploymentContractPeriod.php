<?php

namespace App\Modules\HumanResources\Application\Commands;

use App\Modules\HumanResources\Domain\Exceptions\EmploymentRelationshipAlreadyEndedException;
use App\Modules\HumanResources\Domain\Exceptions\EmploymentRelationshipNotContractSchemeException;
use App\Modules\HumanResources\Domain\Exceptions\InvalidEmploymentContractPeriodDateException;
use App\Modules\HumanResources\Domain\Exceptions\InvalidEmploymentContractTermException;
use App\Modules\HumanResources\Domain\Exceptions\InvalidEmploymentContractTypeException;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\EmploymentContractPeriod;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\EmploymentRelationship;
use App\Modules\Platform\Infrastructure\Persistence\Postgres\PostgresErrorClassifier as Errors;
use App\Modules\Reference\Infrastructure\Persistence\Eloquent\ContractType;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;

/**
 * Records one employment contract period — the initial contract or a renewal — for a CONTRACT
 * Employment Relationship (docs/employment-contract-foundation-specification.md §S21.9,
 * ADR-S21-001). One explicit command covers both, because a renewal is exactly "a new period
 * that takes effect after the latest one". The previous period's identity, contract type,
 * effective_from and contractual_effective_to (the originally agreed term) are always preserved.
 * When the renewal takes effect BEFORE the previous agreed term ended, the previous period is
 * TEMPORALLY CLOSED — its actual effective_to is set to exactly the renewal's effective_from — which
 * is temporal closure, not destructive historical replacement. A renewal at or after the previous
 * term end changes nothing on the previous period (a lapse between them stays visible as "no
 * contract in force"). Nothing is renewed automatically.
 *
 * The EmploymentRelationship is re-fetched fresh with lockForUpdate() as the first statement —
 * exactly the S10–S20 discipline — serialising this command against a concurrent
 * EndEmploymentRelationship (same row lock via its scoped UPDATE) or another recording/renewal on
 * the same relationship; the employment_contract_periods_no_overlap EXCLUDE constraint is the
 * independent database-level backstop.
 */
final class RecordEmploymentContractPeriod
{
    /**
     * @throws EmploymentRelationshipAlreadyEndedException|EmploymentRelationshipNotContractSchemeException
     * @throws InvalidEmploymentContractTypeException|InvalidEmploymentContractPeriodDateException
     * @throws InvalidEmploymentContractTermException
     */
    public function handle(
        EmploymentRelationship $relationship,
        ContractType $contractType,
        string $effectiveFrom,
        string $contractualEffectiveTo,
    ): EmploymentContractPeriod {
        $freshRelationship = EmploymentRelationship::query()
            ->where('id', $relationship->getKey())
            ->lockForUpdate()
            ->firstOrFail();

        if ($freshRelationship->end_knowledge_state === 'KNOWN') {
            throw new EmploymentRelationshipAlreadyEndedException;
        }

        // PERMANENT employment has no contract lifecycle (ADR-S21-001 §4). The scheme is S09's own
        // write-time record of ref.employment_types.code and is immutable afterwards.
        if ($freshRelationship->employee_number_scheme !== 'CONTRACT') {
            throw new EmploymentRelationshipNotContractSchemeException;
        }

        $freshContractType = ContractType::query()
            ->where('id', $contractType->getKey())
            ->first();

        if ($freshContractType === null || ! $freshContractType->is_active) {
            throw new InvalidEmploymentContractTypeException;
        }

        $newFrom = Carbon::parse($effectiveFrom);

        // A contract MAY start on the relationship's own effective_from (ADR-S21-001 §5) but never
        // before it. effective_from is immutable after CreateEmploymentRelationship, so this
        // application-level comparison carries no concurrency risk (S10 spec §7.3 disclosure).
        if ($newFrom->lt($freshRelationship->effective_from)) {
            throw new InvalidEmploymentContractPeriodDateException;
        }

        if (! Carbon::parse($contractualEffectiveTo)->gt($newFrom)) {
            throw new InvalidEmploymentContractTermException;
        }

        // Compared against the LATEST recorded period (whatever its validity), so a backdated
        // period can never be inserted before, or on top of, later history. The relationship row
        // lock above makes this read race-free against every other writer of this table.
        $latestPeriod = EmploymentContractPeriod::query()
            ->where('employment_relationship_id', $freshRelationship->getKey())
            ->orderByDesc('effective_from')
            ->first();

        if ($latestPeriod !== null && $newFrom->lte($latestPeriod->effective_from)) {
            throw new InvalidEmploymentContractPeriodDateException;
        }

        // Renewal before the previous period's actual validity ended: close that validity at
        // exactly the renewal date. Its agreed term (contractual_effective_to) is never touched.
        if ($latestPeriod !== null && ($latestPeriod->effective_to === null || $latestPeriod->effective_to->gt($newFrom))) {
            try {
                $latestPeriod->update(['effective_to' => $effectiveFrom]);
            } catch (QueryException $e) {
                if (Errors::isCheckViolation($e)) {
                    throw new InvalidEmploymentContractPeriodDateException;
                }

                throw $e;
            }
        }

        $period = new EmploymentContractPeriod([
            'employment_relationship_id' => $freshRelationship->getKey(),
            'contract_type_id' => $freshContractType->getKey(),
            'effective_from' => $effectiveFrom,
            'effective_to' => $contractualEffectiveTo,
            'contractual_effective_to' => $contractualEffectiveTo,
            'contract_end_knowledge_state' => 'KNOWN',
        ]);

        try {
            $period->save();
        } catch (QueryException $e) {
            if (Errors::isExclusionViolation($e)) {
                throw new InvalidEmploymentContractPeriodDateException;
            }

            if (Errors::isCheckViolation($e)) {
                throw new InvalidEmploymentContractTermException;
            }

            throw $e;
        }

        return $period->refresh();
    }
}
