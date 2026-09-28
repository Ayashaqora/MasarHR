<?php

namespace App\Modules\Reference\Presentation\Http\Controllers;

use App\Modules\Audit\Application\AuditedCommandExecutor;
use App\Modules\Audit\Domain\AuditSpec;
use App\Modules\Platform\Presentation\Http\Middleware\ResolveCommandContext;
use App\Modules\Reference\Application\Commands\ActivateSpecialty;
use App\Modules\Reference\Application\Commands\CreateSpecialty;
use App\Modules\Reference\Application\Commands\DeactivateSpecialty;
use App\Modules\Reference\Application\Commands\UpdateSpecialtyMetadata;
use App\Modules\Reference\Infrastructure\Persistence\Eloquent\Specialty;
use App\Modules\Reference\Presentation\Http\Resources\SimpleReferenceValueResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * S25 Specialty catalog lifecycle (docs/specialty-catalog-administration-foundation-specification.md,
 * ADR-S25-001): Create / UpdateMetadata / Activate / Deactivate only — no hard delete route exists
 * (S05 §9, reused unchanged). Structured identically to the S13 JobTitleController: every write
 * routes through AuditedCommandExecutor so the mutation and its MUTATION audit entry commit or roll
 * back together, reusing the four abstract simple-reference-value command bases and
 * SimpleReferenceValueResource — no new abstraction. Administers catalog rows only: it never
 * assigns a specialty to a Person or Employment Relationship and never creates or changes an S06
 * specialty → cadre-category mapping period.
 */
class SpecialtyController
{
    public function index(): JsonResponse
    {
        $specialties = Specialty::query()->orderBy('display_order')->orderBy('code')->paginate(50);

        return SimpleReferenceValueResource::collection($specialties)->response();
    }

    public function show(Specialty $specialty): JsonResponse
    {
        return (new SimpleReferenceValueResource($specialty))->response();
    }

    public function store(Request $request, CreateSpecialty $command, AuditedCommandExecutor $executor): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:64', 'regex:/^[a-z0-9_]+$/'],
            'name_ar' => ['required', 'string', 'max:255'],
            'name_en' => ['nullable', 'string', 'max:255'],
            'display_order' => ['nullable', 'integer', 'min:0', 'max:32767'],
        ]);

        $context = ResolveCommandContext::from($request);

        $spec = new AuditSpec(
            action: 'reference.specialty.create',
            targetType: 'reference_specialty',
            targetId: fn (Specialty $created) => $created->getKey(),
            changes: fn (Specialty $created) => [
                'code' => $created->code,
                'name_ar' => $created->name_ar,
                'name_en' => $created->name_en,
                'display_order' => $created->display_order,
            ],
            metadata: fn () => [],
        );

        $specialty = $executor->run(
            $context,
            $spec,
            fn () => $command->handle($data['code'], $data['name_ar'], $data['name_en'] ?? null, $data['display_order'] ?? null),
        );

        return (new SimpleReferenceValueResource($specialty))->response()->setStatusCode(201);
    }

    public function updateMetadata(Request $request, Specialty $specialty, UpdateSpecialtyMetadata $command, AuditedCommandExecutor $executor): JsonResponse
    {
        $data = $request->validate([
            'name_ar' => ['required', 'string', 'max:255'],
            'name_en' => ['nullable', 'string', 'max:255'],
            'display_order' => ['nullable', 'integer', 'min:0', 'max:32767'],
            'expected_version' => ['required', 'integer', 'min:1'],
        ]);

        $context = ResolveCommandContext::from($request);
        $previous = [
            'name_ar' => $specialty->name_ar,
            'name_en' => $specialty->name_en,
            'display_order' => $specialty->display_order,
        ];

        $spec = new AuditSpec(
            action: 'reference.specialty.metadata.update',
            targetType: 'reference_specialty',
            targetId: fn (Specialty $updated) => $updated->getKey(),
            changes: fn (Specialty $updated) => [
                'name_ar' => ['from' => $previous['name_ar'], 'to' => $updated->name_ar],
                'name_en' => ['from' => $previous['name_en'], 'to' => $updated->name_en],
                'display_order' => ['from' => $previous['display_order'], 'to' => $updated->display_order],
            ],
            metadata: fn () => [],
        );

        $updated = $executor->run(
            $context,
            $spec,
            fn () => $command->handle($specialty, $data['name_ar'], $data['name_en'] ?? null, $data['display_order'] ?? null, $data['expected_version']),
        );

        return (new SimpleReferenceValueResource($updated))->response();
    }

    public function activate(Request $request, Specialty $specialty, ActivateSpecialty $command, AuditedCommandExecutor $executor): JsonResponse
    {
        $data = $request->validate(['expected_version' => ['required', 'integer', 'min:1']]);

        $context = ResolveCommandContext::from($request);

        $spec = new AuditSpec(
            action: 'reference.specialty.activate',
            targetType: 'reference_specialty',
            targetId: fn (Specialty $updated) => $updated->getKey(),
            changes: fn () => ['is_active' => ['from' => false, 'to' => true]],
            metadata: fn () => [],
        );

        $updated = $executor->run($context, $spec, fn () => $command->handle($specialty, $data['expected_version']));

        return (new SimpleReferenceValueResource($updated))->response();
    }

    public function deactivate(Request $request, Specialty $specialty, DeactivateSpecialty $command, AuditedCommandExecutor $executor): JsonResponse
    {
        $data = $request->validate(['expected_version' => ['required', 'integer', 'min:1']]);

        $context = ResolveCommandContext::from($request);

        $spec = new AuditSpec(
            action: 'reference.specialty.deactivate',
            targetType: 'reference_specialty',
            targetId: fn (Specialty $updated) => $updated->getKey(),
            changes: fn () => ['is_active' => ['from' => true, 'to' => false]],
            metadata: fn () => [],
        );

        $updated = $executor->run($context, $spec, fn () => $command->handle($specialty, $data['expected_version']));

        return (new SimpleReferenceValueResource($updated))->response();
    }
}
