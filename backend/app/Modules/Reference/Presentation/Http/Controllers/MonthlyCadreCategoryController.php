<?php

namespace App\Modules\Reference\Presentation\Http\Controllers;

use App\Modules\Audit\Application\AuditedCommandExecutor;
use App\Modules\Audit\Domain\AuditSpec;
use App\Modules\Platform\Presentation\Http\Middleware\ResolveCommandContext;
use App\Modules\Reference\Application\Commands\ActivateMonthlyCadreCategory;
use App\Modules\Reference\Application\Commands\CreateMonthlyCadreCategory;
use App\Modules\Reference\Application\Commands\DeactivateMonthlyCadreCategory;
use App\Modules\Reference\Application\Commands\UpdateMonthlyCadreCategoryMetadata;
use App\Modules\Reference\Infrastructure\Persistence\Eloquent\MonthlyCadreCategory;
use App\Modules\Reference\Presentation\Http\Resources\SimpleReferenceValueResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * S06 MonthlyCadreCategory lifecycle: Create / UpdateMetadata / Activate / Deactivate only — no
 * hard delete route exists, identical shape to DecisionTypeController (S05 §9/S06 spec §12.1).
 */
class MonthlyCadreCategoryController
{
    public function index(): JsonResponse
    {
        $categories = MonthlyCadreCategory::query()->orderBy('display_order')->orderBy('code')->paginate(50);

        return SimpleReferenceValueResource::collection($categories)->response();
    }

    public function show(MonthlyCadreCategory $monthlyCadreCategory): JsonResponse
    {
        return (new SimpleReferenceValueResource($monthlyCadreCategory))->response();
    }

    public function store(Request $request, CreateMonthlyCadreCategory $command, AuditedCommandExecutor $executor): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:64', 'regex:/^[a-z0-9_]+$/'],
            'name_ar' => ['required', 'string', 'max:255'],
            'name_en' => ['nullable', 'string', 'max:255'],
            'display_order' => ['nullable', 'integer', 'min:0', 'max:32767'],
        ]);

        $context = ResolveCommandContext::from($request);

        $spec = new AuditSpec(
            action: 'reference.monthly_cadre_category.create',
            targetType: 'reference_monthly_cadre_category',
            targetId: fn (MonthlyCadreCategory $created) => $created->getKey(),
            changes: fn (MonthlyCadreCategory $created) => [
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

    public function updateMetadata(Request $request, MonthlyCadreCategory $monthlyCadreCategory, UpdateMonthlyCadreCategoryMetadata $command, AuditedCommandExecutor $executor): JsonResponse
    {
        $data = $request->validate([
            'name_ar' => ['required', 'string', 'max:255'],
            'name_en' => ['nullable', 'string', 'max:255'],
            'display_order' => ['nullable', 'integer', 'min:0', 'max:32767'],
            'expected_version' => ['required', 'integer', 'min:1'],
        ]);

        $context = ResolveCommandContext::from($request);
        $previous = [
            'name_ar' => $monthlyCadreCategory->name_ar,
            'name_en' => $monthlyCadreCategory->name_en,
            'display_order' => $monthlyCadreCategory->display_order,
        ];

        $spec = new AuditSpec(
            action: 'reference.monthly_cadre_category.metadata.update',
            targetType: 'reference_monthly_cadre_category',
            targetId: fn (MonthlyCadreCategory $updated) => $updated->getKey(),
            changes: fn (MonthlyCadreCategory $updated) => [
                'name_ar' => ['from' => $previous['name_ar'], 'to' => $updated->name_ar],
                'name_en' => ['from' => $previous['name_en'], 'to' => $updated->name_en],
                'display_order' => ['from' => $previous['display_order'], 'to' => $updated->display_order],
            ],
            metadata: fn () => [],
        );

        $updated = $executor->run(
            $context,
            $spec,
            fn () => $command->handle($monthlyCadreCategory, $data['name_ar'], $data['name_en'] ?? null, $data['display_order'] ?? null, $data['expected_version']),
        );

        return (new SimpleReferenceValueResource($updated))->response();
    }

    public function activate(Request $request, MonthlyCadreCategory $monthlyCadreCategory, ActivateMonthlyCadreCategory $command, AuditedCommandExecutor $executor): JsonResponse
    {
        $data = $request->validate(['expected_version' => ['required', 'integer', 'min:1']]);

        $context = ResolveCommandContext::from($request);

        $spec = new AuditSpec(
            action: 'reference.monthly_cadre_category.activate',
            targetType: 'reference_monthly_cadre_category',
            targetId: fn (MonthlyCadreCategory $updated) => $updated->getKey(),
            changes: fn () => ['is_active' => ['from' => false, 'to' => true]],
            metadata: fn () => [],
        );

        $updated = $executor->run($context, $spec, fn () => $command->handle($monthlyCadreCategory, $data['expected_version']));

        return (new SimpleReferenceValueResource($updated))->response();
    }

    public function deactivate(Request $request, MonthlyCadreCategory $monthlyCadreCategory, DeactivateMonthlyCadreCategory $command, AuditedCommandExecutor $executor): JsonResponse
    {
        $data = $request->validate(['expected_version' => ['required', 'integer', 'min:1']]);

        $context = ResolveCommandContext::from($request);

        $spec = new AuditSpec(
            action: 'reference.monthly_cadre_category.deactivate',
            targetType: 'reference_monthly_cadre_category',
            targetId: fn (MonthlyCadreCategory $updated) => $updated->getKey(),
            changes: fn () => ['is_active' => ['from' => true, 'to' => false]],
            metadata: fn () => [],
        );

        $updated = $executor->run($context, $spec, fn () => $command->handle($monthlyCadreCategory, $data['expected_version']));

        return (new SimpleReferenceValueResource($updated))->response();
    }
}
