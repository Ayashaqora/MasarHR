<?php

namespace App\Modules\Reference\Presentation\Http\Controllers;

use App\Modules\Audit\Application\AuditedCommandExecutor;
use App\Modules\Audit\Domain\AuditSpec;
use App\Modules\Platform\Presentation\Http\Middleware\ResolveCommandContext;
use App\Modules\Reference\Application\Commands\ActivateSupervisoryTitle;
use App\Modules\Reference\Application\Commands\CreateSupervisoryTitle;
use App\Modules\Reference\Application\Commands\DeactivateSupervisoryTitle;
use App\Modules\Reference\Application\Commands\UpdateSupervisoryTitleMetadata;
use App\Modules\Reference\Infrastructure\Persistence\Eloquent\SupervisoryTitle;
use App\Modules\Reference\Presentation\Http\Resources\SimpleReferenceValueResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * S13 SupervisoryTitle lifecycle: Create / UpdateMetadata / Activate / Deactivate only — no hard delete
 * route exists (S05 §9, reused unchanged). Every write routes through AuditedCommandExecutor
 * exactly as GenderController/DecisionTypeController route S05 Reference-module writes, so the
 * mutation and its MUTATION audit entry commit or roll back together. This controller reuses the
 * existing four abstract simple-reference-value command bases and SimpleReferenceValueResource —
 * no new abstraction is introduced (docs/reference-catalog-administration-foundation-specification.md
 * §6/§12).
 */
class SupervisoryTitleController
{
    public function index(): JsonResponse
    {
        $supervisoryTitles = SupervisoryTitle::query()->orderBy('display_order')->orderBy('code')->paginate(50);

        return SimpleReferenceValueResource::collection($supervisoryTitles)->response();
    }

    public function show(SupervisoryTitle $supervisoryTitle): JsonResponse
    {
        return (new SimpleReferenceValueResource($supervisoryTitle))->response();
    }

    public function store(Request $request, CreateSupervisoryTitle $command, AuditedCommandExecutor $executor): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:64', 'regex:/^[a-z0-9_]+$/'],
            'name_ar' => ['required', 'string', 'max:255'],
            'name_en' => ['nullable', 'string', 'max:255'],
            'display_order' => ['nullable', 'integer', 'min:0', 'max:32767'],
        ]);

        $context = ResolveCommandContext::from($request);

        $spec = new AuditSpec(
            action: 'reference.supervisory_title.create',
            targetType: 'reference_supervisory_title',
            targetId: fn (SupervisoryTitle $created) => $created->getKey(),
            changes: fn (SupervisoryTitle $created) => [
                'code' => $created->code,
                'name_ar' => $created->name_ar,
                'name_en' => $created->name_en,
                'display_order' => $created->display_order,
            ],
            metadata: fn () => [],
        );

        $supervisoryTitle = $executor->run(
            $context,
            $spec,
            fn () => $command->handle($data['code'], $data['name_ar'], $data['name_en'] ?? null, $data['display_order'] ?? null),
        );

        return (new SimpleReferenceValueResource($supervisoryTitle))->response()->setStatusCode(201);
    }

    public function updateMetadata(Request $request, SupervisoryTitle $supervisoryTitle, UpdateSupervisoryTitleMetadata $command, AuditedCommandExecutor $executor): JsonResponse
    {
        $data = $request->validate([
            'name_ar' => ['required', 'string', 'max:255'],
            'name_en' => ['nullable', 'string', 'max:255'],
            'display_order' => ['nullable', 'integer', 'min:0', 'max:32767'],
            'expected_version' => ['required', 'integer', 'min:1'],
        ]);

        $context = ResolveCommandContext::from($request);
        $previous = [
            'name_ar' => $supervisoryTitle->name_ar,
            'name_en' => $supervisoryTitle->name_en,
            'display_order' => $supervisoryTitle->display_order,
        ];

        $spec = new AuditSpec(
            action: 'reference.supervisory_title.metadata.update',
            targetType: 'reference_supervisory_title',
            targetId: fn (SupervisoryTitle $updated) => $updated->getKey(),
            changes: fn (SupervisoryTitle $updated) => [
                'name_ar' => ['from' => $previous['name_ar'], 'to' => $updated->name_ar],
                'name_en' => ['from' => $previous['name_en'], 'to' => $updated->name_en],
                'display_order' => ['from' => $previous['display_order'], 'to' => $updated->display_order],
            ],
            metadata: fn () => [],
        );

        $updated = $executor->run(
            $context,
            $spec,
            fn () => $command->handle($supervisoryTitle, $data['name_ar'], $data['name_en'] ?? null, $data['display_order'] ?? null, $data['expected_version']),
        );

        return (new SimpleReferenceValueResource($updated))->response();
    }

    public function activate(Request $request, SupervisoryTitle $supervisoryTitle, ActivateSupervisoryTitle $command, AuditedCommandExecutor $executor): JsonResponse
    {
        $data = $request->validate(['expected_version' => ['required', 'integer', 'min:1']]);

        $context = ResolveCommandContext::from($request);

        $spec = new AuditSpec(
            action: 'reference.supervisory_title.activate',
            targetType: 'reference_supervisory_title',
            targetId: fn (SupervisoryTitle $updated) => $updated->getKey(),
            changes: fn () => ['is_active' => ['from' => false, 'to' => true]],
            metadata: fn () => [],
        );

        $updated = $executor->run($context, $spec, fn () => $command->handle($supervisoryTitle, $data['expected_version']));

        return (new SimpleReferenceValueResource($updated))->response();
    }

    public function deactivate(Request $request, SupervisoryTitle $supervisoryTitle, DeactivateSupervisoryTitle $command, AuditedCommandExecutor $executor): JsonResponse
    {
        $data = $request->validate(['expected_version' => ['required', 'integer', 'min:1']]);

        $context = ResolveCommandContext::from($request);

        $spec = new AuditSpec(
            action: 'reference.supervisory_title.deactivate',
            targetType: 'reference_supervisory_title',
            targetId: fn (SupervisoryTitle $updated) => $updated->getKey(),
            changes: fn () => ['is_active' => ['from' => true, 'to' => false]],
            metadata: fn () => [],
        );

        $updated = $executor->run($context, $spec, fn () => $command->handle($supervisoryTitle, $data['expected_version']));

        return (new SimpleReferenceValueResource($updated))->response();
    }
}
