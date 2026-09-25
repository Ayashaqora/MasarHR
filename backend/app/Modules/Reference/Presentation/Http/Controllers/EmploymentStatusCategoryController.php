<?php

namespace App\Modules\Reference\Presentation\Http\Controllers;

use App\Modules\Audit\Application\AuditedCommandExecutor;
use App\Modules\Audit\Domain\AuditSpec;
use App\Modules\Platform\Presentation\Http\Middleware\ResolveCommandContext;
use App\Modules\Reference\Application\Commands\ActivateEmploymentStatusCategory;
use App\Modules\Reference\Application\Commands\CreateEmploymentStatusCategory;
use App\Modules\Reference\Application\Commands\DeactivateEmploymentStatusCategory;
use App\Modules\Reference\Application\Commands\UpdateEmploymentStatusCategoryMetadata;
use App\Modules\Reference\Infrastructure\Persistence\Eloquent\EmploymentStatusCategory;
use App\Modules\Reference\Presentation\Http\Resources\SimpleReferenceValueResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * S05 EmploymentStatusCategory lifecycle: Create / UpdateMetadata / Activate / Deactivate only — no hard delete route
 * exists (§9). Every write routes through AuditedCommandExecutor exactly as RoleController routes
 * Security-module writes (§17), so the mutation and its MUTATION audit entry commit or roll back
 * together.
 */
class EmploymentStatusCategoryController
{
    public function index(): JsonResponse
    {
        $employmentStatusCategories = EmploymentStatusCategory::query()->orderBy('display_order')->orderBy('code')->paginate(50);

        return SimpleReferenceValueResource::collection($employmentStatusCategories)->response();
    }

    public function show(EmploymentStatusCategory $employmentStatusCategory): JsonResponse
    {
        return (new SimpleReferenceValueResource($employmentStatusCategory))->response();
    }

    public function store(Request $request, CreateEmploymentStatusCategory $command, AuditedCommandExecutor $executor): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:64', 'regex:/^[a-z0-9_]+$/'],
            'name_ar' => ['required', 'string', 'max:255'],
            'name_en' => ['nullable', 'string', 'max:255'],
            'display_order' => ['nullable', 'integer', 'min:0', 'max:32767'],
        ]);

        $context = ResolveCommandContext::from($request);

        $spec = new AuditSpec(
            action: 'reference.employment_status_category.create',
            targetType: 'reference_employment_status_category',
            targetId: fn (EmploymentStatusCategory $created) => $created->getKey(),
            changes: fn (EmploymentStatusCategory $created) => [
                'code' => $created->code,
                'name_ar' => $created->name_ar,
                'name_en' => $created->name_en,
                'display_order' => $created->display_order,
            ],
            metadata: fn () => [],
        );

        $employmentStatusCategory = $executor->run(
            $context,
            $spec,
            fn () => $command->handle($data['code'], $data['name_ar'], $data['name_en'] ?? null, $data['display_order'] ?? null),
        );

        return (new SimpleReferenceValueResource($employmentStatusCategory))->response()->setStatusCode(201);
    }

    public function updateMetadata(Request $request, EmploymentStatusCategory $employmentStatusCategory, UpdateEmploymentStatusCategoryMetadata $command, AuditedCommandExecutor $executor): JsonResponse
    {
        $data = $request->validate([
            'name_ar' => ['required', 'string', 'max:255'],
            'name_en' => ['nullable', 'string', 'max:255'],
            'display_order' => ['nullable', 'integer', 'min:0', 'max:32767'],
            'expected_version' => ['required', 'integer', 'min:1'],
        ]);

        $context = ResolveCommandContext::from($request);
        $previous = [
            'name_ar' => $employmentStatusCategory->name_ar,
            'name_en' => $employmentStatusCategory->name_en,
            'display_order' => $employmentStatusCategory->display_order,
        ];

        $spec = new AuditSpec(
            action: 'reference.employment_status_category.metadata.update',
            targetType: 'reference_employment_status_category',
            targetId: fn (EmploymentStatusCategory $updated) => $updated->getKey(),
            changes: fn (EmploymentStatusCategory $updated) => [
                'name_ar' => ['from' => $previous['name_ar'], 'to' => $updated->name_ar],
                'name_en' => ['from' => $previous['name_en'], 'to' => $updated->name_en],
                'display_order' => ['from' => $previous['display_order'], 'to' => $updated->display_order],
            ],
            metadata: fn () => [],
        );

        $updated = $executor->run(
            $context,
            $spec,
            fn () => $command->handle($employmentStatusCategory, $data['name_ar'], $data['name_en'] ?? null, $data['display_order'] ?? null, $data['expected_version']),
        );

        return (new SimpleReferenceValueResource($updated))->response();
    }

    public function activate(Request $request, EmploymentStatusCategory $employmentStatusCategory, ActivateEmploymentStatusCategory $command, AuditedCommandExecutor $executor): JsonResponse
    {
        $data = $request->validate(['expected_version' => ['required', 'integer', 'min:1']]);

        $context = ResolveCommandContext::from($request);

        $spec = new AuditSpec(
            action: 'reference.employment_status_category.activate',
            targetType: 'reference_employment_status_category',
            targetId: fn (EmploymentStatusCategory $updated) => $updated->getKey(),
            changes: fn () => ['is_active' => ['from' => false, 'to' => true]],
            metadata: fn () => [],
        );

        $updated = $executor->run($context, $spec, fn () => $command->handle($employmentStatusCategory, $data['expected_version']));

        return (new SimpleReferenceValueResource($updated))->response();
    }

    public function deactivate(Request $request, EmploymentStatusCategory $employmentStatusCategory, DeactivateEmploymentStatusCategory $command, AuditedCommandExecutor $executor): JsonResponse
    {
        $data = $request->validate(['expected_version' => ['required', 'integer', 'min:1']]);

        $context = ResolveCommandContext::from($request);

        $spec = new AuditSpec(
            action: 'reference.employment_status_category.deactivate',
            targetType: 'reference_employment_status_category',
            targetId: fn (EmploymentStatusCategory $updated) => $updated->getKey(),
            changes: fn () => ['is_active' => ['from' => true, 'to' => false]],
            metadata: fn () => [],
        );

        $updated = $executor->run($context, $spec, fn () => $command->handle($employmentStatusCategory, $data['expected_version']));

        return (new SimpleReferenceValueResource($updated))->response();
    }
}
