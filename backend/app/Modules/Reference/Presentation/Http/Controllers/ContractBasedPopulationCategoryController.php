<?php

namespace App\Modules\Reference\Presentation\Http\Controllers;

use App\Modules\Audit\Application\AuditedCommandExecutor;
use App\Modules\Audit\Domain\AuditSpec;
use App\Modules\Platform\Presentation\Http\Middleware\ResolveCommandContext;
use App\Modules\Reference\Application\Commands\ActivateContractBasedPopulationCategory;
use App\Modules\Reference\Application\Commands\CreateContractBasedPopulationCategory;
use App\Modules\Reference\Application\Commands\DeactivateContractBasedPopulationCategory;
use App\Modules\Reference\Application\Commands\UpdateContractBasedPopulationCategoryMetadata;
use App\Modules\Reference\Infrastructure\Persistence\Eloquent\ContractBasedPopulationCategory;
use App\Modules\Reference\Presentation\Http\Resources\SimpleReferenceValueResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * S06 ContractBasedPopulationCategory lifecycle: Create / UpdateMetadata / Activate / Deactivate
 * only — no hard delete route exists, identical shape to DecisionTypeController (S05 §9/S06 spec
 * §12.4).
 */
class ContractBasedPopulationCategoryController
{
    public function index(): JsonResponse
    {
        $categories = ContractBasedPopulationCategory::query()->orderBy('display_order')->orderBy('code')->paginate(50);

        return SimpleReferenceValueResource::collection($categories)->response();
    }

    public function show(ContractBasedPopulationCategory $contractBasedPopulationCategory): JsonResponse
    {
        return (new SimpleReferenceValueResource($contractBasedPopulationCategory))->response();
    }

    public function store(Request $request, CreateContractBasedPopulationCategory $command, AuditedCommandExecutor $executor): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:64', 'regex:/^[a-z0-9_]+$/'],
            'name_ar' => ['required', 'string', 'max:255'],
            'name_en' => ['nullable', 'string', 'max:255'],
            'display_order' => ['nullable', 'integer', 'min:0', 'max:32767'],
        ]);

        $context = ResolveCommandContext::from($request);

        $spec = new AuditSpec(
            action: 'reference.contract_based_population_category.create',
            targetType: 'reference_contract_based_population_category',
            targetId: fn (ContractBasedPopulationCategory $created) => $created->getKey(),
            changes: fn (ContractBasedPopulationCategory $created) => [
                'code' => $created->code,
                'name_ar' => $created->name_ar,
                'name_en' => $created->name_en,
                'display_order' => $created->display_order,
            ],
            metadata: fn () => [],
        );

        $category = $executor->run(
            $context,
            $spec,
            fn () => $command->handle($data['code'], $data['name_ar'], $data['name_en'] ?? null, $data['display_order'] ?? null),
        );

        return (new SimpleReferenceValueResource($category))->response()->setStatusCode(201);
    }

    public function updateMetadata(Request $request, ContractBasedPopulationCategory $contractBasedPopulationCategory, UpdateContractBasedPopulationCategoryMetadata $command, AuditedCommandExecutor $executor): JsonResponse
    {
        $data = $request->validate([
            'name_ar' => ['required', 'string', 'max:255'],
            'name_en' => ['nullable', 'string', 'max:255'],
            'display_order' => ['nullable', 'integer', 'min:0', 'max:32767'],
            'expected_version' => ['required', 'integer', 'min:1'],
        ]);

        $context = ResolveCommandContext::from($request);
        $previous = [
            'name_ar' => $contractBasedPopulationCategory->name_ar,
            'name_en' => $contractBasedPopulationCategory->name_en,
            'display_order' => $contractBasedPopulationCategory->display_order,
        ];

        $spec = new AuditSpec(
            action: 'reference.contract_based_population_category.metadata.update',
            targetType: 'reference_contract_based_population_category',
            targetId: fn (ContractBasedPopulationCategory $updated) => $updated->getKey(),
            changes: fn (ContractBasedPopulationCategory $updated) => [
                'name_ar' => ['from' => $previous['name_ar'], 'to' => $updated->name_ar],
                'name_en' => ['from' => $previous['name_en'], 'to' => $updated->name_en],
                'display_order' => ['from' => $previous['display_order'], 'to' => $updated->display_order],
            ],
            metadata: fn () => [],
        );

        $updated = $executor->run(
            $context,
            $spec,
            fn () => $command->handle($contractBasedPopulationCategory, $data['name_ar'], $data['name_en'] ?? null, $data['display_order'] ?? null, $data['expected_version']),
        );

        return (new SimpleReferenceValueResource($updated))->response();
    }

    public function activate(Request $request, ContractBasedPopulationCategory $contractBasedPopulationCategory, ActivateContractBasedPopulationCategory $command, AuditedCommandExecutor $executor): JsonResponse
    {
        $data = $request->validate(['expected_version' => ['required', 'integer', 'min:1']]);

        $context = ResolveCommandContext::from($request);

        $spec = new AuditSpec(
            action: 'reference.contract_based_population_category.activate',
            targetType: 'reference_contract_based_population_category',
            targetId: fn (ContractBasedPopulationCategory $updated) => $updated->getKey(),
            changes: fn () => ['is_active' => ['from' => false, 'to' => true]],
            metadata: fn () => [],
        );

        $updated = $executor->run($context, $spec, fn () => $command->handle($contractBasedPopulationCategory, $data['expected_version']));

        return (new SimpleReferenceValueResource($updated))->response();
    }

    public function deactivate(Request $request, ContractBasedPopulationCategory $contractBasedPopulationCategory, DeactivateContractBasedPopulationCategory $command, AuditedCommandExecutor $executor): JsonResponse
    {
        $data = $request->validate(['expected_version' => ['required', 'integer', 'min:1']]);

        $context = ResolveCommandContext::from($request);

        $spec = new AuditSpec(
            action: 'reference.contract_based_population_category.deactivate',
            targetType: 'reference_contract_based_population_category',
            targetId: fn (ContractBasedPopulationCategory $updated) => $updated->getKey(),
            changes: fn () => ['is_active' => ['from' => true, 'to' => false]],
            metadata: fn () => [],
        );

        $updated = $executor->run($context, $spec, fn () => $command->handle($contractBasedPopulationCategory, $data['expected_version']));

        return (new SimpleReferenceValueResource($updated))->response();
    }
}
