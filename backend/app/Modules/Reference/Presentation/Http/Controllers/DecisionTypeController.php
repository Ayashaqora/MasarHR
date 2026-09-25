<?php

namespace App\Modules\Reference\Presentation\Http\Controllers;

use App\Modules\Audit\Application\AuditedCommandExecutor;
use App\Modules\Audit\Domain\AuditSpec;
use App\Modules\Platform\Presentation\Http\Middleware\ResolveCommandContext;
use App\Modules\Reference\Application\Commands\ActivateDecisionType;
use App\Modules\Reference\Application\Commands\CreateDecisionType;
use App\Modules\Reference\Application\Commands\DeactivateDecisionType;
use App\Modules\Reference\Application\Commands\UpdateDecisionTypeMetadata;
use App\Modules\Reference\Infrastructure\Persistence\Eloquent\DecisionType;
use App\Modules\Reference\Presentation\Http\Resources\SimpleReferenceValueResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * S05 DecisionType lifecycle: Create / UpdateMetadata / Activate / Deactivate only — no hard delete route
 * exists (§9). Every write routes through AuditedCommandExecutor exactly as RoleController routes
 * Security-module writes (§17), so the mutation and its MUTATION audit entry commit or roll back
 * together.
 */
class DecisionTypeController
{
    public function index(): JsonResponse
    {
        $decisionTypes = DecisionType::query()->orderBy('display_order')->orderBy('code')->paginate(50);

        return SimpleReferenceValueResource::collection($decisionTypes)->response();
    }

    public function show(DecisionType $decisionType): JsonResponse
    {
        return (new SimpleReferenceValueResource($decisionType))->response();
    }

    public function store(Request $request, CreateDecisionType $command, AuditedCommandExecutor $executor): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:64', 'regex:/^[a-z0-9_]+$/'],
            'name_ar' => ['required', 'string', 'max:255'],
            'name_en' => ['nullable', 'string', 'max:255'],
            'display_order' => ['nullable', 'integer', 'min:0', 'max:32767'],
        ]);

        $context = ResolveCommandContext::from($request);

        $spec = new AuditSpec(
            action: 'reference.decision_type.create',
            targetType: 'reference_decision_type',
            targetId: fn (DecisionType $created) => $created->getKey(),
            changes: fn (DecisionType $created) => [
                'code' => $created->code,
                'name_ar' => $created->name_ar,
                'name_en' => $created->name_en,
                'display_order' => $created->display_order,
            ],
            metadata: fn () => [],
        );

        $decisionType = $executor->run(
            $context,
            $spec,
            fn () => $command->handle($data['code'], $data['name_ar'], $data['name_en'] ?? null, $data['display_order'] ?? null),
        );

        return (new SimpleReferenceValueResource($decisionType))->response()->setStatusCode(201);
    }

    public function updateMetadata(Request $request, DecisionType $decisionType, UpdateDecisionTypeMetadata $command, AuditedCommandExecutor $executor): JsonResponse
    {
        $data = $request->validate([
            'name_ar' => ['required', 'string', 'max:255'],
            'name_en' => ['nullable', 'string', 'max:255'],
            'display_order' => ['nullable', 'integer', 'min:0', 'max:32767'],
            'expected_version' => ['required', 'integer', 'min:1'],
        ]);

        $context = ResolveCommandContext::from($request);
        $previous = [
            'name_ar' => $decisionType->name_ar,
            'name_en' => $decisionType->name_en,
            'display_order' => $decisionType->display_order,
        ];

        $spec = new AuditSpec(
            action: 'reference.decision_type.metadata.update',
            targetType: 'reference_decision_type',
            targetId: fn (DecisionType $updated) => $updated->getKey(),
            changes: fn (DecisionType $updated) => [
                'name_ar' => ['from' => $previous['name_ar'], 'to' => $updated->name_ar],
                'name_en' => ['from' => $previous['name_en'], 'to' => $updated->name_en],
                'display_order' => ['from' => $previous['display_order'], 'to' => $updated->display_order],
            ],
            metadata: fn () => [],
        );

        $updated = $executor->run(
            $context,
            $spec,
            fn () => $command->handle($decisionType, $data['name_ar'], $data['name_en'] ?? null, $data['display_order'] ?? null, $data['expected_version']),
        );

        return (new SimpleReferenceValueResource($updated))->response();
    }

    public function activate(Request $request, DecisionType $decisionType, ActivateDecisionType $command, AuditedCommandExecutor $executor): JsonResponse
    {
        $data = $request->validate(['expected_version' => ['required', 'integer', 'min:1']]);

        $context = ResolveCommandContext::from($request);

        $spec = new AuditSpec(
            action: 'reference.decision_type.activate',
            targetType: 'reference_decision_type',
            targetId: fn (DecisionType $updated) => $updated->getKey(),
            changes: fn () => ['is_active' => ['from' => false, 'to' => true]],
            metadata: fn () => [],
        );

        $updated = $executor->run($context, $spec, fn () => $command->handle($decisionType, $data['expected_version']));

        return (new SimpleReferenceValueResource($updated))->response();
    }

    public function deactivate(Request $request, DecisionType $decisionType, DeactivateDecisionType $command, AuditedCommandExecutor $executor): JsonResponse
    {
        $data = $request->validate(['expected_version' => ['required', 'integer', 'min:1']]);

        $context = ResolveCommandContext::from($request);

        $spec = new AuditSpec(
            action: 'reference.decision_type.deactivate',
            targetType: 'reference_decision_type',
            targetId: fn (DecisionType $updated) => $updated->getKey(),
            changes: fn () => ['is_active' => ['from' => true, 'to' => false]],
            metadata: fn () => [],
        );

        $updated = $executor->run($context, $spec, fn () => $command->handle($decisionType, $data['expected_version']));

        return (new SimpleReferenceValueResource($updated))->response();
    }
}
