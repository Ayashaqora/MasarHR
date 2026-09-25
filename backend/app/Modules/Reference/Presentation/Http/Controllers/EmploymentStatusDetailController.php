<?php

namespace App\Modules\Reference\Presentation\Http\Controllers;

use App\Modules\Audit\Application\AuditedCommandExecutor;
use App\Modules\Audit\Domain\AuditSpec;
use App\Modules\Platform\Presentation\Http\Middleware\ResolveCommandContext;
use App\Modules\Reference\Application\Commands\ActivateEmploymentStatusDetail;
use App\Modules\Reference\Application\Commands\CreateEmploymentStatusDetail;
use App\Modules\Reference\Application\Commands\DeactivateEmploymentStatusDetail;
use App\Modules\Reference\Application\Commands\UpdateEmploymentStatusDetailMetadata;
use App\Modules\Reference\Infrastructure\Persistence\Eloquent\EmploymentStatusCategory;
use App\Modules\Reference\Infrastructure\Persistence\Eloquent\EmploymentStatusDetail;
use App\Modules\Reference\Presentation\Http\Resources\EmploymentStatusDetailResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * S05 EmploymentStatusDetail lifecycle. store() additionally resolves category_id via
 * findOrFail() rather than an `exists:` validation rule — the same reasoning as
 * RoleController::grantPermission() (a dotted schema.table string in `exists:` is misparsed as
 * "connection.table", so "ref" would be read as a connection name). category_id is immutable
 * after creation (§19) — updateMetadata never changes it.
 */
class EmploymentStatusDetailController
{
    public function index(): JsonResponse
    {
        $details = EmploymentStatusDetail::query()->orderBy('display_order')->orderBy('code')->paginate(50);

        return EmploymentStatusDetailResource::collection($details)->response();
    }

    public function show(EmploymentStatusDetail $employmentStatusDetail): JsonResponse
    {
        return (new EmploymentStatusDetailResource($employmentStatusDetail))->response();
    }

    public function store(Request $request, CreateEmploymentStatusDetail $command, AuditedCommandExecutor $executor): JsonResponse
    {
        $data = $request->validate([
            'category_id' => ['required', 'uuid'],
            'code' => ['required', 'string', 'max:64', 'regex:/^[a-z0-9_]+$/'],
            'name_ar' => ['required', 'string', 'max:255'],
            'name_en' => ['nullable', 'string', 'max:255'],
            'display_order' => ['nullable', 'integer', 'min:0', 'max:32767'],
        ]);

        $category = EmploymentStatusCategory::query()->findOrFail($data['category_id']);
        $context = ResolveCommandContext::from($request);

        $spec = new AuditSpec(
            action: 'reference.employment_status_detail.create',
            targetType: 'reference_employment_status_detail',
            targetId: fn (EmploymentStatusDetail $created) => $created->getKey(),
            changes: fn (EmploymentStatusDetail $created) => [
                'category_id' => $created->category_id,
                'code' => $created->code,
                'name_ar' => $created->name_ar,
                'name_en' => $created->name_en,
                'display_order' => $created->display_order,
            ],
            metadata: fn () => [],
        );

        $detail = $executor->run(
            $context,
            $spec,
            fn () => $command->handle($category->getKey(), $data['code'], $data['name_ar'], $data['name_en'] ?? null, $data['display_order'] ?? null),
        );

        return (new EmploymentStatusDetailResource($detail))->response()->setStatusCode(201);
    }

    public function updateMetadata(Request $request, EmploymentStatusDetail $employmentStatusDetail, UpdateEmploymentStatusDetailMetadata $command, AuditedCommandExecutor $executor): JsonResponse
    {
        $data = $request->validate([
            'name_ar' => ['required', 'string', 'max:255'],
            'name_en' => ['nullable', 'string', 'max:255'],
            'display_order' => ['nullable', 'integer', 'min:0', 'max:32767'],
            'expected_version' => ['required', 'integer', 'min:1'],
        ]);

        $context = ResolveCommandContext::from($request);
        $previous = [
            'name_ar' => $employmentStatusDetail->name_ar,
            'name_en' => $employmentStatusDetail->name_en,
            'display_order' => $employmentStatusDetail->display_order,
        ];

        $spec = new AuditSpec(
            action: 'reference.employment_status_detail.metadata.update',
            targetType: 'reference_employment_status_detail',
            targetId: fn (EmploymentStatusDetail $updated) => $updated->getKey(),
            changes: fn (EmploymentStatusDetail $updated) => [
                'name_ar' => ['from' => $previous['name_ar'], 'to' => $updated->name_ar],
                'name_en' => ['from' => $previous['name_en'], 'to' => $updated->name_en],
                'display_order' => ['from' => $previous['display_order'], 'to' => $updated->display_order],
            ],
            metadata: fn () => [],
        );

        $updated = $executor->run(
            $context,
            $spec,
            fn () => $command->handle($employmentStatusDetail, $data['name_ar'], $data['name_en'] ?? null, $data['display_order'] ?? null, $data['expected_version']),
        );

        return (new EmploymentStatusDetailResource($updated))->response();
    }

    public function activate(Request $request, EmploymentStatusDetail $employmentStatusDetail, ActivateEmploymentStatusDetail $command, AuditedCommandExecutor $executor): JsonResponse
    {
        $data = $request->validate(['expected_version' => ['required', 'integer', 'min:1']]);

        $context = ResolveCommandContext::from($request);

        $spec = new AuditSpec(
            action: 'reference.employment_status_detail.activate',
            targetType: 'reference_employment_status_detail',
            targetId: fn (EmploymentStatusDetail $updated) => $updated->getKey(),
            changes: fn () => ['is_active' => ['from' => false, 'to' => true]],
            metadata: fn () => [],
        );

        $updated = $executor->run($context, $spec, fn () => $command->handle($employmentStatusDetail, $data['expected_version']));

        return (new EmploymentStatusDetailResource($updated))->response();
    }

    public function deactivate(Request $request, EmploymentStatusDetail $employmentStatusDetail, DeactivateEmploymentStatusDetail $command, AuditedCommandExecutor $executor): JsonResponse
    {
        $data = $request->validate(['expected_version' => ['required', 'integer', 'min:1']]);

        $context = ResolveCommandContext::from($request);

        $spec = new AuditSpec(
            action: 'reference.employment_status_detail.deactivate',
            targetType: 'reference_employment_status_detail',
            targetId: fn (EmploymentStatusDetail $updated) => $updated->getKey(),
            changes: fn () => ['is_active' => ['from' => true, 'to' => false]],
            metadata: fn () => [],
        );

        $updated = $executor->run($context, $spec, fn () => $command->handle($employmentStatusDetail, $data['expected_version']));

        return (new EmploymentStatusDetailResource($updated))->response();
    }
}
