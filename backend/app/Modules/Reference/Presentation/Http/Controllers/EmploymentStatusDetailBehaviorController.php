<?php

namespace App\Modules\Reference\Presentation\Http\Controllers;

use App\Modules\Audit\Application\AuditedCommandExecutor;
use App\Modules\Audit\Domain\AuditSpec;
use App\Modules\Platform\Presentation\Http\Middleware\ResolveCommandContext;
use App\Modules\Reference\Application\Commands\DefineEmploymentStatusDetailBehaviorPeriod;
use App\Modules\Reference\Domain\Exceptions\OverlappingBehaviorPeriodException;
use App\Modules\Reference\Infrastructure\Persistence\Eloquent\EmploymentStatusDetail;
use App\Modules\Reference\Infrastructure\Persistence\Eloquent\EmploymentStatusDetailBehavior;
use App\Modules\Reference\Presentation\Http\Resources\EmploymentStatusDetailBehaviorResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * S05 §12: only two operations — list the periods defined for a status detail, and define a new
 * one. No update/delete route exists; a period, once inserted, is never changed (D4).
 */
class EmploymentStatusDetailBehaviorController
{
    public function index(EmploymentStatusDetail $employmentStatusDetail): JsonResponse
    {
        $periods = EmploymentStatusDetailBehavior::query()
            ->where('status_detail_id', $employmentStatusDetail->getKey())
            ->orderBy('effective_from')
            ->get();

        return EmploymentStatusDetailBehaviorResource::collection($periods)->response();
    }

    /** @throws OverlappingBehaviorPeriodException */
    public function store(
        Request $request,
        EmploymentStatusDetail $employmentStatusDetail,
        DefineEmploymentStatusDetailBehaviorPeriod $command,
        AuditedCommandExecutor $executor,
    ): JsonResponse {
        $data = $request->validate([
            'effective_from' => ['required', 'date_format:Y-m-d'],
            'effective_to' => ['nullable', 'date_format:Y-m-d', 'after:effective_from'],
            'participates_in_active_workforce' => ['required', 'boolean'],
            'is_ongoing_relationship' => ['required', 'boolean'],
            'is_relationship_ending' => ['required', 'boolean'],
            'is_terminal' => ['required', 'boolean'],
            'allows_reappointment' => ['nullable', 'boolean'],
            'counts_in_monthly_reporting' => ['nullable', 'boolean'],
        ]);

        $context = ResolveCommandContext::from($request);

        $spec = new AuditSpec(
            action: 'reference.employment_status_detail_behavior.period.define',
            targetType: 'reference_employment_status_detail_behavior',
            targetId: fn (EmploymentStatusDetailBehavior $created) => $created->getKey(),
            changes: fn (EmploymentStatusDetailBehavior $created) => [
                'status_detail_id' => $created->status_detail_id,
                'effective_from' => $created->effective_from?->toDateString(),
                'effective_to' => $created->effective_to?->toDateString(),
                'participates_in_active_workforce' => $created->participates_in_active_workforce,
                'is_ongoing_relationship' => $created->is_ongoing_relationship,
                'is_relationship_ending' => $created->is_relationship_ending,
                'is_terminal' => $created->is_terminal,
                'allows_reappointment' => $created->allows_reappointment,
                'counts_in_monthly_reporting' => $created->counts_in_monthly_reporting,
            ],
            metadata: fn () => [],
        );

        $period = $executor->run(
            $context,
            $spec,
            fn () => $command->handle(
                $employmentStatusDetail,
                $data['effective_from'],
                $data['effective_to'] ?? null,
                $data['participates_in_active_workforce'],
                $data['is_ongoing_relationship'],
                $data['is_relationship_ending'],
                $data['is_terminal'],
                $data['allows_reappointment'] ?? null,
                $data['counts_in_monthly_reporting'] ?? null,
            ),
        );

        return (new EmploymentStatusDetailBehaviorResource($period))->response()->setStatusCode(201);
    }
}
