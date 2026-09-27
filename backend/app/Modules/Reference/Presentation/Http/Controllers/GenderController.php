<?php

namespace App\Modules\Reference\Presentation\Http\Controllers;

use App\Modules\Audit\Application\AuditedCommandExecutor;
use App\Modules\Audit\Domain\AuditSpec;
use App\Modules\Platform\Presentation\Http\Middleware\ResolveCommandContext;
use App\Modules\Reference\Application\Commands\ActivateGender;
use App\Modules\Reference\Application\Commands\CreateGender;
use App\Modules\Reference\Application\Commands\DeactivateGender;
use App\Modules\Reference\Application\Commands\UpdateGenderMetadata;
use App\Modules\Reference\Infrastructure\Persistence\Eloquent\Gender;
use App\Modules\Reference\Presentation\Http\Resources\SimpleReferenceValueResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * S05 Gender lifecycle: Create / UpdateMetadata / Activate / Deactivate only — no hard delete route
 * exists (§9). Every write routes through AuditedCommandExecutor exactly as RoleController routes
 * Security-module writes (§17), so the mutation and its MUTATION audit entry commit or roll back
 * together.
 */
class GenderController
{
    public function index(): JsonResponse
    {
        $genders = Gender::query()->orderBy('display_order')->orderBy('code')->paginate(50);

        return SimpleReferenceValueResource::collection($genders)->response();
    }

    public function show(Gender $gender): JsonResponse
    {
        return (new SimpleReferenceValueResource($gender))->response();
    }

    public function store(Request $request, CreateGender $command, AuditedCommandExecutor $executor): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:64', 'regex:/^[a-z0-9_]+$/'],
            'name_ar' => ['required', 'string', 'max:255'],
            'name_en' => ['nullable', 'string', 'max:255'],
            'display_order' => ['nullable', 'integer', 'min:0', 'max:32767'],
        ]);

        $context = ResolveCommandContext::from($request);

        $spec = new AuditSpec(
            action: 'reference.gender.create',
            targetType: 'reference_gender',
            targetId: fn (Gender $created) => $created->getKey(),
            changes: fn (Gender $created) => [
                'code' => $created->code,
                'name_ar' => $created->name_ar,
                'name_en' => $created->name_en,
                'display_order' => $created->display_order,
            ],
            metadata: fn () => [],
        );

        $gender = $executor->run(
            $context,
            $spec,
            fn () => $command->handle($data['code'], $data['name_ar'], $data['name_en'] ?? null, $data['display_order'] ?? null),
        );

        return (new SimpleReferenceValueResource($gender))->response()->setStatusCode(201);
    }

    public function updateMetadata(Request $request, Gender $gender, UpdateGenderMetadata $command, AuditedCommandExecutor $executor): JsonResponse
    {
        $data = $request->validate([
            'name_ar' => ['required', 'string', 'max:255'],
            'name_en' => ['nullable', 'string', 'max:255'],
            'display_order' => ['nullable', 'integer', 'min:0', 'max:32767'],
            'expected_version' => ['required', 'integer', 'min:1'],
        ]);

        $context = ResolveCommandContext::from($request);
        $previous = [
            'name_ar' => $gender->name_ar,
            'name_en' => $gender->name_en,
            'display_order' => $gender->display_order,
        ];

        $spec = new AuditSpec(
            action: 'reference.gender.metadata.update',
            targetType: 'reference_gender',
            targetId: fn (Gender $updated) => $updated->getKey(),
            changes: fn (Gender $updated) => [
                'name_ar' => ['from' => $previous['name_ar'], 'to' => $updated->name_ar],
                'name_en' => ['from' => $previous['name_en'], 'to' => $updated->name_en],
                'display_order' => ['from' => $previous['display_order'], 'to' => $updated->display_order],
            ],
            metadata: fn () => [],
        );

        $updated = $executor->run(
            $context,
            $spec,
            fn () => $command->handle($gender, $data['name_ar'], $data['name_en'] ?? null, $data['display_order'] ?? null, $data['expected_version']),
        );

        return (new SimpleReferenceValueResource($updated))->response();
    }

    public function activate(Request $request, Gender $gender, ActivateGender $command, AuditedCommandExecutor $executor): JsonResponse
    {
        $data = $request->validate(['expected_version' => ['required', 'integer', 'min:1']]);

        $context = ResolveCommandContext::from($request);

        $spec = new AuditSpec(
            action: 'reference.gender.activate',
            targetType: 'reference_gender',
            targetId: fn (Gender $updated) => $updated->getKey(),
            changes: fn () => ['is_active' => ['from' => false, 'to' => true]],
            metadata: fn () => [],
        );

        $updated = $executor->run($context, $spec, fn () => $command->handle($gender, $data['expected_version']));

        return (new SimpleReferenceValueResource($updated))->response();
    }

    public function deactivate(Request $request, Gender $gender, DeactivateGender $command, AuditedCommandExecutor $executor): JsonResponse
    {
        $data = $request->validate(['expected_version' => ['required', 'integer', 'min:1']]);

        $context = ResolveCommandContext::from($request);

        $spec = new AuditSpec(
            action: 'reference.gender.deactivate',
            targetType: 'reference_gender',
            targetId: fn (Gender $updated) => $updated->getKey(),
            changes: fn () => ['is_active' => ['from' => true, 'to' => false]],
            metadata: fn () => [],
        );

        $updated = $executor->run($context, $spec, fn () => $command->handle($gender, $data['expected_version']));

        return (new SimpleReferenceValueResource($updated))->response();
    }
}
