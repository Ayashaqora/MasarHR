<?php

namespace App\Modules\Reference\Presentation\Http\Controllers;

use App\Modules\Audit\Application\AuditedCommandExecutor;
use App\Modules\Audit\Domain\AuditSpec;
use App\Modules\Platform\Presentation\Http\Middleware\ResolveCommandContext;
use App\Modules\Reference\Application\Commands\ActivateMaritalStatus;
use App\Modules\Reference\Application\Commands\CreateMaritalStatus;
use App\Modules\Reference\Application\Commands\DeactivateMaritalStatus;
use App\Modules\Reference\Application\Commands\UpdateMaritalStatusMetadata;
use App\Modules\Reference\Infrastructure\Persistence\Eloquent\MaritalStatus;
use App\Modules\Reference\Presentation\Http\Resources\SimpleReferenceValueResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * S05 MaritalStatus lifecycle: Create / UpdateMetadata / Activate / Deactivate only — no hard delete route
 * exists (§9). Every write routes through AuditedCommandExecutor exactly as RoleController routes
 * Security-module writes (§17), so the mutation and its MUTATION audit entry commit or roll back
 * together.
 */
class MaritalStatusController
{
    public function index(): JsonResponse
    {
        $maritalStatuses = MaritalStatus::query()->orderBy('display_order')->orderBy('code')->paginate(50);

        return SimpleReferenceValueResource::collection($maritalStatuses)->response();
    }

    public function show(MaritalStatus $maritalStatus): JsonResponse
    {
        return (new SimpleReferenceValueResource($maritalStatus))->response();
    }

    public function store(Request $request, CreateMaritalStatus $command, AuditedCommandExecutor $executor): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:64', 'regex:/^[a-z0-9_]+$/'],
            'name_ar' => ['required', 'string', 'max:255'],
            'name_en' => ['nullable', 'string', 'max:255'],
            'display_order' => ['nullable', 'integer', 'min:0', 'max:32767'],
        ]);

        $context = ResolveCommandContext::from($request);

        $spec = new AuditSpec(
            action: 'reference.marital_status.create',
            targetType: 'reference_marital_status',
            targetId: fn (MaritalStatus $created) => $created->getKey(),
            changes: fn (MaritalStatus $created) => [
                'code' => $created->code,
                'name_ar' => $created->name_ar,
                'name_en' => $created->name_en,
                'display_order' => $created->display_order,
            ],
            metadata: fn () => [],
        );

        $maritalStatus = $executor->run(
            $context,
            $spec,
            fn () => $command->handle($data['code'], $data['name_ar'], $data['name_en'] ?? null, $data['display_order'] ?? null),
        );

        return (new SimpleReferenceValueResource($maritalStatus))->response()->setStatusCode(201);
    }

    public function updateMetadata(Request $request, MaritalStatus $maritalStatus, UpdateMaritalStatusMetadata $command, AuditedCommandExecutor $executor): JsonResponse
    {
        $data = $request->validate([
            'name_ar' => ['required', 'string', 'max:255'],
            'name_en' => ['nullable', 'string', 'max:255'],
            'display_order' => ['nullable', 'integer', 'min:0', 'max:32767'],
            'expected_version' => ['required', 'integer', 'min:1'],
        ]);

        $context = ResolveCommandContext::from($request);
        $previous = [
            'name_ar' => $maritalStatus->name_ar,
            'name_en' => $maritalStatus->name_en,
            'display_order' => $maritalStatus->display_order,
        ];

        $spec = new AuditSpec(
            action: 'reference.marital_status.metadata.update',
            targetType: 'reference_marital_status',
            targetId: fn (MaritalStatus $updated) => $updated->getKey(),
            changes: fn (MaritalStatus $updated) => [
                'name_ar' => ['from' => $previous['name_ar'], 'to' => $updated->name_ar],
                'name_en' => ['from' => $previous['name_en'], 'to' => $updated->name_en],
                'display_order' => ['from' => $previous['display_order'], 'to' => $updated->display_order],
            ],
            metadata: fn () => [],
        );

        $updated = $executor->run(
            $context,
            $spec,
            fn () => $command->handle($maritalStatus, $data['name_ar'], $data['name_en'] ?? null, $data['display_order'] ?? null, $data['expected_version']),
        );

        return (new SimpleReferenceValueResource($updated))->response();
    }

    public function activate(Request $request, MaritalStatus $maritalStatus, ActivateMaritalStatus $command, AuditedCommandExecutor $executor): JsonResponse
    {
        $data = $request->validate(['expected_version' => ['required', 'integer', 'min:1']]);

        $context = ResolveCommandContext::from($request);

        $spec = new AuditSpec(
            action: 'reference.marital_status.activate',
            targetType: 'reference_marital_status',
            targetId: fn (MaritalStatus $updated) => $updated->getKey(),
            changes: fn () => ['is_active' => ['from' => false, 'to' => true]],
            metadata: fn () => [],
        );

        $updated = $executor->run($context, $spec, fn () => $command->handle($maritalStatus, $data['expected_version']));

        return (new SimpleReferenceValueResource($updated))->response();
    }

    public function deactivate(Request $request, MaritalStatus $maritalStatus, DeactivateMaritalStatus $command, AuditedCommandExecutor $executor): JsonResponse
    {
        $data = $request->validate(['expected_version' => ['required', 'integer', 'min:1']]);

        $context = ResolveCommandContext::from($request);

        $spec = new AuditSpec(
            action: 'reference.marital_status.deactivate',
            targetType: 'reference_marital_status',
            targetId: fn (MaritalStatus $updated) => $updated->getKey(),
            changes: fn () => ['is_active' => ['from' => true, 'to' => false]],
            metadata: fn () => [],
        );

        $updated = $executor->run($context, $spec, fn () => $command->handle($maritalStatus, $data['expected_version']));

        return (new SimpleReferenceValueResource($updated))->response();
    }
}
