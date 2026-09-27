<?php

namespace App\Modules\Reference\Presentation\Http\Controllers;

use App\Modules\Audit\Application\AuditedCommandExecutor;
use App\Modules\Audit\Domain\AuditSpec;
use App\Modules\Platform\Presentation\Http\Middleware\ResolveCommandContext;
use App\Modules\Reference\Application\Commands\ActivateJobTitle;
use App\Modules\Reference\Application\Commands\CreateJobTitle;
use App\Modules\Reference\Application\Commands\DeactivateJobTitle;
use App\Modules\Reference\Application\Commands\UpdateJobTitleMetadata;
use App\Modules\Reference\Infrastructure\Persistence\Eloquent\JobTitle;
use App\Modules\Reference\Presentation\Http\Resources\SimpleReferenceValueResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * S13 JobTitle lifecycle: Create / UpdateMetadata / Activate / Deactivate only — no hard delete
 * route exists (S05 §9, reused unchanged). Every write routes through AuditedCommandExecutor
 * exactly as GenderController/DecisionTypeController route S05 Reference-module writes, so the
 * mutation and its MUTATION audit entry commit or roll back together. This controller reuses the
 * existing four abstract simple-reference-value command bases and SimpleReferenceValueResource —
 * no new abstraction is introduced (docs/reference-catalog-administration-foundation-specification.md
 * §6/§12).
 */
class JobTitleController
{
    public function index(): JsonResponse
    {
        $jobTitles = JobTitle::query()->orderBy('display_order')->orderBy('code')->paginate(50);

        return SimpleReferenceValueResource::collection($jobTitles)->response();
    }

    public function show(JobTitle $jobTitle): JsonResponse
    {
        return (new SimpleReferenceValueResource($jobTitle))->response();
    }

    public function store(Request $request, CreateJobTitle $command, AuditedCommandExecutor $executor): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:64', 'regex:/^[a-z0-9_]+$/'],
            'name_ar' => ['required', 'string', 'max:255'],
            'name_en' => ['nullable', 'string', 'max:255'],
            'display_order' => ['nullable', 'integer', 'min:0', 'max:32767'],
        ]);

        $context = ResolveCommandContext::from($request);

        $spec = new AuditSpec(
            action: 'reference.job_title.create',
            targetType: 'reference_job_title',
            targetId: fn (JobTitle $created) => $created->getKey(),
            changes: fn (JobTitle $created) => [
                'code' => $created->code,
                'name_ar' => $created->name_ar,
                'name_en' => $created->name_en,
                'display_order' => $created->display_order,
            ],
            metadata: fn () => [],
        );

        $jobTitle = $executor->run(
            $context,
            $spec,
            fn () => $command->handle($data['code'], $data['name_ar'], $data['name_en'] ?? null, $data['display_order'] ?? null),
        );

        return (new SimpleReferenceValueResource($jobTitle))->response()->setStatusCode(201);
    }

    public function updateMetadata(Request $request, JobTitle $jobTitle, UpdateJobTitleMetadata $command, AuditedCommandExecutor $executor): JsonResponse
    {
        $data = $request->validate([
            'name_ar' => ['required', 'string', 'max:255'],
            'name_en' => ['nullable', 'string', 'max:255'],
            'display_order' => ['nullable', 'integer', 'min:0', 'max:32767'],
            'expected_version' => ['required', 'integer', 'min:1'],
        ]);

        $context = ResolveCommandContext::from($request);
        $previous = [
            'name_ar' => $jobTitle->name_ar,
            'name_en' => $jobTitle->name_en,
            'display_order' => $jobTitle->display_order,
        ];

        $spec = new AuditSpec(
            action: 'reference.job_title.metadata.update',
            targetType: 'reference_job_title',
            targetId: fn (JobTitle $updated) => $updated->getKey(),
            changes: fn (JobTitle $updated) => [
                'name_ar' => ['from' => $previous['name_ar'], 'to' => $updated->name_ar],
                'name_en' => ['from' => $previous['name_en'], 'to' => $updated->name_en],
                'display_order' => ['from' => $previous['display_order'], 'to' => $updated->display_order],
            ],
            metadata: fn () => [],
        );

        $updated = $executor->run(
            $context,
            $spec,
            fn () => $command->handle($jobTitle, $data['name_ar'], $data['name_en'] ?? null, $data['display_order'] ?? null, $data['expected_version']),
        );

        return (new SimpleReferenceValueResource($updated))->response();
    }

    public function activate(Request $request, JobTitle $jobTitle, ActivateJobTitle $command, AuditedCommandExecutor $executor): JsonResponse
    {
        $data = $request->validate(['expected_version' => ['required', 'integer', 'min:1']]);

        $context = ResolveCommandContext::from($request);

        $spec = new AuditSpec(
            action: 'reference.job_title.activate',
            targetType: 'reference_job_title',
            targetId: fn (JobTitle $updated) => $updated->getKey(),
            changes: fn () => ['is_active' => ['from' => false, 'to' => true]],
            metadata: fn () => [],
        );

        $updated = $executor->run($context, $spec, fn () => $command->handle($jobTitle, $data['expected_version']));

        return (new SimpleReferenceValueResource($updated))->response();
    }

    public function deactivate(Request $request, JobTitle $jobTitle, DeactivateJobTitle $command, AuditedCommandExecutor $executor): JsonResponse
    {
        $data = $request->validate(['expected_version' => ['required', 'integer', 'min:1']]);

        $context = ResolveCommandContext::from($request);

        $spec = new AuditSpec(
            action: 'reference.job_title.deactivate',
            targetType: 'reference_job_title',
            targetId: fn (JobTitle $updated) => $updated->getKey(),
            changes: fn () => ['is_active' => ['from' => true, 'to' => false]],
            metadata: fn () => [],
        );

        $updated = $executor->run($context, $spec, fn () => $command->handle($jobTitle, $data['expected_version']));

        return (new SimpleReferenceValueResource($updated))->response();
    }
}
