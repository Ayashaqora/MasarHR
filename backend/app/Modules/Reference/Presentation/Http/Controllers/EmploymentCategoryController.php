<?php

namespace App\Modules\Reference\Presentation\Http\Controllers;

use App\Modules\Audit\Application\AuditedCommandExecutor;
use App\Modules\Audit\Domain\AuditSpec;
use App\Modules\Platform\Presentation\Http\Middleware\ResolveCommandContext;
use App\Modules\Reference\Application\Commands\ActivateEmploymentCategory;
use App\Modules\Reference\Application\Commands\CreateEmploymentCategory;
use App\Modules\Reference\Application\Commands\DeactivateEmploymentCategory;
use App\Modules\Reference\Application\Commands\UpdateEmploymentCategoryMetadata;
use App\Modules\Reference\Infrastructure\Persistence\Eloquent\EmploymentCategory;
use App\Modules\Reference\Presentation\Http\Resources\SimpleReferenceValueResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * S13 EmploymentCategory lifecycle: Create / UpdateMetadata / Activate / Deactivate only — no hard delete
 * route exists (S05 §9, reused unchanged). Every write routes through AuditedCommandExecutor
 * exactly as GenderController/DecisionTypeController route S05 Reference-module writes, so the
 * mutation and its MUTATION audit entry commit or roll back together. This controller reuses the
 * existing four abstract simple-reference-value command bases and SimpleReferenceValueResource —
 * no new abstraction is introduced (docs/reference-catalog-administration-foundation-specification.md
 * §6/§12).
 */
class EmploymentCategoryController
{
    public function index(): JsonResponse
    {
        $employmentCategorys = EmploymentCategory::query()->orderBy('display_order')->orderBy('code')->paginate(50);

        return SimpleReferenceValueResource::collection($employmentCategorys)->response();
    }

    public function show(EmploymentCategory $employmentCategory): JsonResponse
    {
        return (new SimpleReferenceValueResource($employmentCategory))->response();
    }

    public function store(Request $request, CreateEmploymentCategory $command, AuditedCommandExecutor $executor): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:64', 'regex:/^[a-z0-9_]+$/'],
            'name_ar' => ['required', 'string', 'max:255'],
            'name_en' => ['nullable', 'string', 'max:255'],
            'display_order' => ['nullable', 'integer', 'min:0', 'max:32767'],
        ]);

        $context = ResolveCommandContext::from($request);

        $spec = new AuditSpec(
            action: 'reference.employment_category.create',
            targetType: 'reference_employment_category',
            targetId: fn (EmploymentCategory $created) => $created->getKey(),
            changes: fn (EmploymentCategory $created) => [
                'code' => $created->code,
                'name_ar' => $created->name_ar,
                'name_en' => $created->name_en,
                'display_order' => $created->display_order,
            ],
            metadata: fn () => [],
        );

        $employmentCategory = $executor->run(
            $context,
            $spec,
            fn () => $command->handle($data['code'], $data['name_ar'], $data['name_en'] ?? null, $data['display_order'] ?? null),
        );

        return (new SimpleReferenceValueResource($employmentCategory))->response()->setStatusCode(201);
    }

    public function updateMetadata(Request $request, EmploymentCategory $employmentCategory, UpdateEmploymentCategoryMetadata $command, AuditedCommandExecutor $executor): JsonResponse
    {
        $data = $request->validate([
            'name_ar' => ['required', 'string', 'max:255'],
            'name_en' => ['nullable', 'string', 'max:255'],
            'display_order' => ['nullable', 'integer', 'min:0', 'max:32767'],
            'expected_version' => ['required', 'integer', 'min:1'],
        ]);

        $context = ResolveCommandContext::from($request);
        $previous = [
            'name_ar' => $employmentCategory->name_ar,
            'name_en' => $employmentCategory->name_en,
            'display_order' => $employmentCategory->display_order,
        ];

        $spec = new AuditSpec(
            action: 'reference.employment_category.metadata.update',
            targetType: 'reference_employment_category',
            targetId: fn (EmploymentCategory $updated) => $updated->getKey(),
            changes: fn (EmploymentCategory $updated) => [
                'name_ar' => ['from' => $previous['name_ar'], 'to' => $updated->name_ar],
                'name_en' => ['from' => $previous['name_en'], 'to' => $updated->name_en],
                'display_order' => ['from' => $previous['display_order'], 'to' => $updated->display_order],
            ],
            metadata: fn () => [],
        );

        $updated = $executor->run(
            $context,
            $spec,
            fn () => $command->handle($employmentCategory, $data['name_ar'], $data['name_en'] ?? null, $data['display_order'] ?? null, $data['expected_version']),
        );

        return (new SimpleReferenceValueResource($updated))->response();
    }

    public function activate(Request $request, EmploymentCategory $employmentCategory, ActivateEmploymentCategory $command, AuditedCommandExecutor $executor): JsonResponse
    {
        $data = $request->validate(['expected_version' => ['required', 'integer', 'min:1']]);

        $context = ResolveCommandContext::from($request);

        $spec = new AuditSpec(
            action: 'reference.employment_category.activate',
            targetType: 'reference_employment_category',
            targetId: fn (EmploymentCategory $updated) => $updated->getKey(),
            changes: fn () => ['is_active' => ['from' => false, 'to' => true]],
            metadata: fn () => [],
        );

        $updated = $executor->run($context, $spec, fn () => $command->handle($employmentCategory, $data['expected_version']));

        return (new SimpleReferenceValueResource($updated))->response();
    }

    public function deactivate(Request $request, EmploymentCategory $employmentCategory, DeactivateEmploymentCategory $command, AuditedCommandExecutor $executor): JsonResponse
    {
        $data = $request->validate(['expected_version' => ['required', 'integer', 'min:1']]);

        $context = ResolveCommandContext::from($request);

        $spec = new AuditSpec(
            action: 'reference.employment_category.deactivate',
            targetType: 'reference_employment_category',
            targetId: fn (EmploymentCategory $updated) => $updated->getKey(),
            changes: fn () => ['is_active' => ['from' => true, 'to' => false]],
            metadata: fn () => [],
        );

        $updated = $executor->run($context, $spec, fn () => $command->handle($employmentCategory, $data['expected_version']));

        return (new SimpleReferenceValueResource($updated))->response();
    }
}
