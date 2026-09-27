<?php

namespace App\Modules\Reference\Presentation\Http\Controllers;

use App\Modules\Audit\Application\AuditedCommandExecutor;
use App\Modules\Audit\Domain\AuditSpec;
use App\Modules\Platform\Presentation\Http\Middleware\ResolveCommandContext;
use App\Modules\Reference\Application\Commands\DefineJobTitleAdministratorClassificationPeriod;
use App\Modules\Reference\Domain\Exceptions\OverlappingBehaviorPeriodException;
use App\Modules\Reference\Infrastructure\Persistence\Eloquent\JobTitle;
use App\Modules\Reference\Infrastructure\Persistence\Eloquent\JobTitleAdministratorClassification;
use App\Modules\Reference\Presentation\Http\Resources\JobTitleAdministratorClassificationResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * S06 spec §12.3/§14/§15: only two operations — list the classification periods defined for a
 * job title, and define a new one. No update/delete route exists; a period, once inserted, is
 * never changed (append-only), identical shape to EmploymentStatusDetailBehaviorController.
 */
class JobTitleAdministratorClassificationController
{
    public function index(JobTitle $jobTitle): JsonResponse
    {
        $periods = JobTitleAdministratorClassification::query()
            ->where('job_title_id', $jobTitle->getKey())
            ->orderBy('effective_from')
            ->get();

        return JobTitleAdministratorClassificationResource::collection($periods)->response();
    }

    /** @throws OverlappingBehaviorPeriodException */
    public function store(
        Request $request,
        JobTitle $jobTitle,
        DefineJobTitleAdministratorClassificationPeriod $command,
        AuditedCommandExecutor $executor,
    ): JsonResponse {
        $data = $request->validate([
            'is_administrator' => ['required', 'boolean'],
            'effective_from' => ['required', 'date_format:Y-m-d'],
            'effective_to' => ['nullable', 'date_format:Y-m-d', 'after:effective_from'],
        ]);

        $context = ResolveCommandContext::from($request);

        $spec = new AuditSpec(
            action: 'reference.job_title_administrator_classification.period.define',
            targetType: 'reference_job_title_administrator_classification',
            targetId: fn (JobTitleAdministratorClassification $created) => $created->getKey(),
            changes: fn (JobTitleAdministratorClassification $created) => [
                'job_title_id' => $created->job_title_id,
                'is_administrator' => $created->is_administrator,
                'effective_from' => $created->effective_from?->toDateString(),
                'effective_to' => $created->effective_to?->toDateString(),
            ],
            metadata: fn () => [],
        );

        $period = $executor->run(
            $context,
            $spec,
            fn () => $command->handle($jobTitle, $data['is_administrator'], $data['effective_from'], $data['effective_to'] ?? null),
        );

        return (new JobTitleAdministratorClassificationResource($period))->response()->setStatusCode(201);
    }
}
