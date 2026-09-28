<?php

namespace App\Modules\HumanResources\Presentation\Http\Controllers;

use App\Modules\Audit\Application\AuditedCommandExecutor;
use App\Modules\Audit\Domain\AuditSpec;
use App\Modules\HumanResources\Application\Commands\RecordEmploymentContractPeriod;
use App\Modules\HumanResources\Application\Queries\ListEmploymentContractPeriodsForRelationship;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\EmploymentContractPeriod;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\EmploymentRelationship;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\Person;
use App\Modules\HumanResources\Presentation\Http\Resources\EmploymentContractPeriodResource;
use App\Modules\Platform\Presentation\Http\Middleware\ResolveCommandContext;
use App\Modules\Reference\Infrastructure\Persistence\Eloquent\ContractType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * S21 Employment Contract surface (docs/employment-contract-foundation-specification.md §S21.14),
 * nested under an existing {person}/{employmentRelationship} — mirrors EmploymentCategoryPeriod-
 * Controller's (S20) shape and plain-RBAC authorization exactly (the route's permission:
 * middleware is the whole gate; no S08 organizational-scope composition, ADR-S21-001 §10). One
 * explicit record action covers the initial contract and renewals: no generic PATCH, no DELETE, no
 * manual contract-end route, and no duplicate of the Reference module's /reference/contract-types
 * administration.
 */
class EmploymentContractPeriodController
{
    public function index(
        Person $person,
        EmploymentRelationship $employmentRelationship,
        ListEmploymentContractPeriodsForRelationship $query,
    ): JsonResponse {
        $this->guardOwnership($person, $employmentRelationship);

        return EmploymentContractPeriodResource::collection($query($employmentRelationship))->response();
    }

    public function store(
        Request $request,
        Person $person,
        EmploymentRelationship $employmentRelationship,
        RecordEmploymentContractPeriod $command,
        AuditedCommandExecutor $executor,
    ): JsonResponse {
        $this->guardOwnership($person, $employmentRelationship);

        $data = $request->validate([
            'contract_type_id' => ['required', 'uuid'],
            'effective_from' => ['required', 'date'],
            'contractual_effective_to' => ['required', 'date'],
        ]);

        // Existence only here (404, mirroring S16/S20); the active-at-command-time rule is enforced
        // by RecordEmploymentContractPeriod against a fresh re-fetch inside the audited transaction.
        $contractType = ContractType::query()->find($data['contract_type_id']);

        if ($contractType === null) {
            throw new NotFoundHttpException('Contract type not found.');
        }

        $context = ResolveCommandContext::from($request);

        // Same TOCTOU-closing shape as EmploymentStatusPeriodController (S15 §17): the relationship
        // row is locked FIRST, so the "previous period" snapshot used for the renewal audit
        // metadata below cannot race a concurrent writer. $executor->run()'s own transaction
        // becomes a savepoint within this one.
        return DB::transaction(function () use ($employmentRelationship, $contractType, $context, $command, $executor, $data) {
            EmploymentRelationship::query()->where('id', $employmentRelationship->getKey())->lockForUpdate()->firstOrFail();

            $previous = EmploymentContractPeriod::query()
                ->where('employment_relationship_id', $employmentRelationship->getKey())
                ->orderByDesc('effective_from')
                ->first();
            $previousEffectiveTo = $previous?->effective_to?->toDateString();

            $spec = new AuditSpec(
                action: 'hr.employment_contract_period.record',
                targetType: 'hr_employment_contract_period',
                targetId: fn (EmploymentContractPeriod $period) => $period->getKey(),
                changes: fn (EmploymentContractPeriod $period) => [
                    'employment_relationship_id' => $period->employment_relationship_id,
                    'contract_type_id' => $period->contract_type_id,
                    'effective_from' => $period->effective_from?->toDateString(),
                    'contractual_effective_to' => $period->contractual_effective_to?->toDateString(),
                ],
                metadata: function () use ($contractType, $previous, $previousEffectiveTo) {
                    $metadata = ['contract_type_code' => $contractType->code];

                    if ($previous !== null) {
                        $metadata['renewal_of_period_id'] = $previous->getKey();

                        $previousNow = EmploymentContractPeriod::query()->find($previous->getKey());
                        $previousNowTo = $previousNow?->effective_to?->toDateString();

                        if ($previousNowTo !== $previousEffectiveTo) {
                            $metadata['previous_period_closed_at'] = $previousNowTo;
                        }
                    }

                    return $metadata;
                },
            );

            $period = $executor->run(
                $context,
                $spec,
                fn () => $command->handle(
                    $employmentRelationship,
                    $contractType,
                    $data['effective_from'],
                    $data['contractual_effective_to'],
                ),
            );

            return (new EmploymentContractPeriodResource($period))->response()->setStatusCode(201);
        });
    }

    private function guardOwnership(Person $person, EmploymentRelationship $employmentRelationship): void
    {
        if ($employmentRelationship->person_id !== $person->getKey()) {
            throw new NotFoundHttpException('Employment relationship not found for this person.');
        }
    }
}
