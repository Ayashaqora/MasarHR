<?php

namespace App\Modules\Reference\Presentation\Http\Controllers;

use App\Modules\Audit\Application\AuditedCommandExecutor;
use App\Modules\Audit\Domain\AuditSpec;
use App\Modules\Platform\Presentation\Http\Middleware\ResolveCommandContext;
use App\Modules\Reference\Application\Commands\ActivateLeaveType;
use App\Modules\Reference\Application\Commands\CreateLeaveType;
use App\Modules\Reference\Application\Commands\DeactivateLeaveType;
use App\Modules\Reference\Application\Commands\UpdateLeaveTypeMetadata;
use App\Modules\Reference\Infrastructure\Persistence\Eloquent\LeaveType;
use App\Modules\Reference\Presentation\Http\Resources\SimpleReferenceValueResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * S13 LeaveType lifecycle: Create / UpdateMetadata / Activate / Deactivate only — no hard delete
 * route exists (S05 §9, reused unchanged). Every write routes through AuditedCommandExecutor
 * exactly as GenderController/DecisionTypeController route S05 Reference-module writes, so the
 * mutation and its MUTATION audit entry commit or roll back together. This controller reuses the
 * existing four abstract simple-reference-value command bases and SimpleReferenceValueResource —
 * no new abstraction is introduced (docs/reference-catalog-administration-foundation-specification.md
 * §6/§12).
 */
class LeaveTypeController
{
    public function index(): JsonResponse
    {
        $leaveTypes = LeaveType::query()->orderBy('display_order')->orderBy('code')->paginate(50);

        return SimpleReferenceValueResource::collection($leaveTypes)->response();
    }

    public function show(LeaveType $leaveType): JsonResponse
    {
        return (new SimpleReferenceValueResource($leaveType))->response();
    }

    public function store(Request $request, CreateLeaveType $command, AuditedCommandExecutor $executor): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:64', 'regex:/^[a-z0-9_]+$/'],
            'name_ar' => ['required', 'string', 'max:255'],
            'name_en' => ['nullable', 'string', 'max:255'],
            'display_order' => ['nullable', 'integer', 'min:0', 'max:32767'],
        ]);

        $context = ResolveCommandContext::from($request);

        $spec = new AuditSpec(
            action: 'reference.leave_type.create',
            targetType: 'reference_leave_type',
            targetId: fn (LeaveType $created) => $created->getKey(),
            changes: fn (LeaveType $created) => [
                'code' => $created->code,
                'name_ar' => $created->name_ar,
                'name_en' => $created->name_en,
                'display_order' => $created->display_order,
            ],
            metadata: fn () => [],
        );

        $leaveType = $executor->run(
            $context,
            $spec,
            fn () => $command->handle($data['code'], $data['name_ar'], $data['name_en'] ?? null, $data['display_order'] ?? null),
        );

        return (new SimpleReferenceValueResource($leaveType))->response()->setStatusCode(201);
    }

    public function updateMetadata(Request $request, LeaveType $leaveType, UpdateLeaveTypeMetadata $command, AuditedCommandExecutor $executor): JsonResponse
    {
        $data = $request->validate([
            'name_ar' => ['required', 'string', 'max:255'],
            'name_en' => ['nullable', 'string', 'max:255'],
            'display_order' => ['nullable', 'integer', 'min:0', 'max:32767'],
            'expected_version' => ['required', 'integer', 'min:1'],
        ]);

        $context = ResolveCommandContext::from($request);
        $previous = [
            'name_ar' => $leaveType->name_ar,
            'name_en' => $leaveType->name_en,
            'display_order' => $leaveType->display_order,
        ];

        $spec = new AuditSpec(
            action: 'reference.leave_type.metadata.update',
            targetType: 'reference_leave_type',
            targetId: fn (LeaveType $updated) => $updated->getKey(),
            changes: fn (LeaveType $updated) => [
                'name_ar' => ['from' => $previous['name_ar'], 'to' => $updated->name_ar],
                'name_en' => ['from' => $previous['name_en'], 'to' => $updated->name_en],
                'display_order' => ['from' => $previous['display_order'], 'to' => $updated->display_order],
            ],
            metadata: fn () => [],
        );

        $updated = $executor->run(
            $context,
            $spec,
            fn () => $command->handle($leaveType, $data['name_ar'], $data['name_en'] ?? null, $data['display_order'] ?? null, $data['expected_version']),
        );

        return (new SimpleReferenceValueResource($updated))->response();
    }

    public function activate(Request $request, LeaveType $leaveType, ActivateLeaveType $command, AuditedCommandExecutor $executor): JsonResponse
    {
        $data = $request->validate(['expected_version' => ['required', 'integer', 'min:1']]);

        $context = ResolveCommandContext::from($request);

        $spec = new AuditSpec(
            action: 'reference.leave_type.activate',
            targetType: 'reference_leave_type',
            targetId: fn (LeaveType $updated) => $updated->getKey(),
            changes: fn () => ['is_active' => ['from' => false, 'to' => true]],
            metadata: fn () => [],
        );

        $updated = $executor->run($context, $spec, fn () => $command->handle($leaveType, $data['expected_version']));

        return (new SimpleReferenceValueResource($updated))->response();
    }

    public function deactivate(Request $request, LeaveType $leaveType, DeactivateLeaveType $command, AuditedCommandExecutor $executor): JsonResponse
    {
        $data = $request->validate(['expected_version' => ['required', 'integer', 'min:1']]);

        $context = ResolveCommandContext::from($request);

        $spec = new AuditSpec(
            action: 'reference.leave_type.deactivate',
            targetType: 'reference_leave_type',
            targetId: fn (LeaveType $updated) => $updated->getKey(),
            changes: fn () => ['is_active' => ['from' => true, 'to' => false]],
            metadata: fn () => [],
        );

        $updated = $executor->run($context, $spec, fn () => $command->handle($leaveType, $data['expected_version']));

        return (new SimpleReferenceValueResource($updated))->response();
    }
}
