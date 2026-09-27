<?php

namespace App\Modules\Reference\Presentation\Http\Controllers;

use App\Modules\Audit\Application\AuditedCommandExecutor;
use App\Modules\Audit\Domain\AuditSpec;
use App\Modules\Platform\Presentation\Http\Middleware\ResolveCommandContext;
use App\Modules\Reference\Application\Commands\DefineContractTypePopulationMappingPeriod;
use App\Modules\Reference\Domain\Exceptions\OverlappingBehaviorPeriodException;
use App\Modules\Reference\Infrastructure\Persistence\Eloquent\ContractBasedPopulationCategory;
use App\Modules\Reference\Infrastructure\Persistence\Eloquent\ContractType;
use App\Modules\Reference\Infrastructure\Persistence\Eloquent\ContractTypePopulationMapping;
use App\Modules\Reference\Presentation\Http\Resources\ContractTypePopulationMappingResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * S06 spec §12.5/§14/§15: only two operations — list the mapping periods defined for a contract
 * type, and define a new one. No update/delete route exists; a period, once inserted, is never
 * changed (append-only), identical shape to EmploymentStatusDetailBehaviorController.
 */
class ContractTypePopulationMappingController
{
    public function index(ContractType $contractType): JsonResponse
    {
        $periods = ContractTypePopulationMapping::query()
            ->where('contract_type_id', $contractType->getKey())
            ->orderBy('effective_from')
            ->get();

        return ContractTypePopulationMappingResource::collection($periods)->response();
    }

    /** @throws OverlappingBehaviorPeriodException */
    public function store(
        Request $request,
        ContractType $contractType,
        DefineContractTypePopulationMappingPeriod $command,
        AuditedCommandExecutor $executor,
    ): JsonResponse {
        // Existence is enforced by findOrFail() below, not by an `exists:` validation rule — same
        // established precedent as RoleController::grantPermission() and
        // SpecialtyCadreCategoryMappingController::store().
        $data = $request->validate([
            'population_category_id' => ['required', 'uuid'],
            'effective_from' => ['required', 'date_format:Y-m-d'],
            'effective_to' => ['nullable', 'date_format:Y-m-d', 'after:effective_from'],
        ]);

        $populationCategory = ContractBasedPopulationCategory::query()->findOrFail($data['population_category_id']);
        $context = ResolveCommandContext::from($request);

        $spec = new AuditSpec(
            action: 'reference.contract_type_population_mapping.period.define',
            targetType: 'reference_contract_type_population_mapping',
            targetId: fn (ContractTypePopulationMapping $created) => $created->getKey(),
            changes: fn (ContractTypePopulationMapping $created) => [
                'contract_type_id' => $created->contract_type_id,
                'population_category_id' => $created->population_category_id,
                'effective_from' => $created->effective_from?->toDateString(),
                'effective_to' => $created->effective_to?->toDateString(),
            ],
            metadata: fn () => [],
        );

        $period = $executor->run(
            $context,
            $spec,
            fn () => $command->handle($contractType, $populationCategory, $data['effective_from'], $data['effective_to'] ?? null),
        );

        return (new ContractTypePopulationMappingResource($period))->response()->setStatusCode(201);
    }
}
