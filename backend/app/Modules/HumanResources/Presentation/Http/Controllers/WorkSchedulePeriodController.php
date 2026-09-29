<?php

namespace App\Modules\HumanResources\Presentation\Http\Controllers;

use App\Modules\Audit\Application\AuditedCommandExecutor;
use App\Modules\Audit\Domain\AuditSpec;
use App\Modules\HumanResources\Application\Commands\RecordWorkSchedulePeriod;
use App\Modules\HumanResources\Application\Queries\ListWorkSchedulePeriodsForRelationship;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\EmploymentRelationship;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\Person;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\WorkSchedulePeriod;
use App\Modules\HumanResources\Presentation\Http\Resources\WorkSchedulePeriodResource;
use App\Modules\Platform\Presentation\Http\Middleware\ResolveCommandContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * S29 Work Schedule surface (docs/work-schedule-foundation-specification.md §S29.13), nested under
 * an existing {person}/{employmentRelationship} — mirrors EmploymentSpecialtyPeriodController (S26):
 * plain RBAC via the route's permission: middleware, and the relationship row is locked first so
 * the "previous period" snapshot used for the temporal-closure audit metadata cannot race a
 * concurrent writer. Explicit record action only: no generic PATCH, no DELETE, no end or
 * correction route, and no weekday administration.
 */
class WorkSchedulePeriodController
{
    public function index(
        Person $person,
        EmploymentRelationship $employmentRelationship,
        ListWorkSchedulePeriodsForRelationship $query,
    ): JsonResponse {
        $this->guardOwnership($person, $employmentRelationship);

        return WorkSchedulePeriodResource::collection($query($employmentRelationship))->response();
    }

    public function store(
        Request $request,
        Person $person,
        EmploymentRelationship $employmentRelationship,
        RecordWorkSchedulePeriod $command,
        AuditedCommandExecutor $executor,
    ): JsonResponse {
        $this->guardOwnership($person, $employmentRelationship);

        // Shape only here; membership, emptiness and duplicate rules live in RecordWorkSchedulePeriod
        // (422 errors.weekdays) so the command enforces them for every caller.
        $data = $request->validate([
            'effective_from' => ['required', 'date'],
            'weekdays' => ['present', 'array'],
            'weekdays.*' => ['string'],
        ]);

        $weekdayCodes = array_values($data['weekdays']);
        $context = ResolveCommandContext::from($request);

        return DB::transaction(function () use ($employmentRelationship, $context, $command, $executor, $data, $weekdayCodes) {
            EmploymentRelationship::query()->where('id', $employmentRelationship->getKey())->lockForUpdate()->firstOrFail();

            $previous = WorkSchedulePeriod::query()
                ->where('employment_relationship_id', $employmentRelationship->getKey())
                ->orderByDesc('effective_from')
                ->first();
            $previousEffectiveTo = $previous?->effective_to?->toDateString();

            $spec = new AuditSpec(
                action: 'hr.work_schedule_period.record',
                targetType: 'hr_work_schedule_period',
                targetId: fn (WorkSchedulePeriod $period) => $period->getKey(),
                changes: fn (WorkSchedulePeriod $period) => [
                    'employment_relationship_id' => $period->employment_relationship_id,
                    'effective_from' => $period->effective_from?->toDateString(),
                    'weekdays' => $period->weekdayCodes(),
                ],
                metadata: function () use ($previous, $previousEffectiveTo) {
                    $metadata = [];

                    if ($previous !== null) {
                        $metadata['previous_period_id'] = $previous->getKey();

                        $previousNowTo = WorkSchedulePeriod::query()->find($previous->getKey())?->effective_to?->toDateString();

                        if ($previousNowTo !== $previousEffectiveTo) {
                            $metadata['previous_period_closed_at'] = $previousNowTo;
                        }
                    }

                    return $metadata;
                },
            );

            $period = $executor->run(
                $context,
                $spec,
                fn () => $command->handle($employmentRelationship, $data['effective_from'], $weekdayCodes),
            );

            return (new WorkSchedulePeriodResource($period))->response()->setStatusCode(201);
        });
    }

    private function guardOwnership(Person $person, EmploymentRelationship $employmentRelationship): void
    {
        if ($employmentRelationship->person_id !== $person->getKey()) {
            throw new NotFoundHttpException('Employment relationship not found for this person.');
        }
    }
}
