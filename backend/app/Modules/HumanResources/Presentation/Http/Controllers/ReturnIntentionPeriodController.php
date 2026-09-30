<?php

namespace App\Modules\HumanResources\Presentation\Http\Controllers;

use App\Modules\Audit\Application\AuditedCommandExecutor;
use App\Modules\Audit\Domain\AuditSpec;
use App\Modules\HumanResources\Application\Commands\RecordReturnIntention;
use App\Modules\HumanResources\Application\Queries\ListReturnIntentionPeriodsForRelationship;
use App\Modules\HumanResources\Application\Queries\ResolveReturnIntentionAsOf;
use App\Modules\HumanResources\Domain\ReturnIntention;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\EmploymentRelationship;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\Person;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\ReturnIntentionPeriod;
use App\Modules\HumanResources\Presentation\Http\Resources\ReturnIntentionPeriodResource;
use App\Modules\Platform\Application\Clock\BusinessDateClock;
use App\Modules\Platform\Presentation\Http\Middleware\ResolveCommandContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * S34 Return Intention surface, nested under {person}/{employmentRelationship}. Explicit record +
 * persisted history + effective as-of only: no generic PATCH and no delete. Return Intention is
 * independent of employment status — nothing here touches a status, movement, schedule or leave row.
 */
class ReturnIntentionPeriodController
{
    private function ensureOwned(Person $person, EmploymentRelationship $relationship): void
    {
        if ($relationship->person_id !== $person->getKey()) {
            throw new NotFoundHttpException('Employment relationship not found for this person.');
        }
    }

    public function index(Person $person, EmploymentRelationship $employmentRelationship, ListReturnIntentionPeriodsForRelationship $query): JsonResponse
    {
        $this->ensureOwned($person, $employmentRelationship);

        return ReturnIntentionPeriodResource::collection($query($employmentRelationship))->response();
    }

    /** Effective Return Intention on a date; `as_of` defaults to the authoritative business date (never the browser's). */
    public function effective(
        Request $request,
        Person $person,
        EmploymentRelationship $employmentRelationship,
        ResolveReturnIntentionAsOf $resolver,
        BusinessDateClock $clock,
    ): JsonResponse {
        $this->ensureOwned($person, $employmentRelationship);

        $data = $request->validate(['as_of' => ['nullable', 'date_format:Y-m-d']]);
        $asOf = $data['as_of'] ?? $clock->today()->toDateString();
        $period = $resolver($employmentRelationship, $asOf);

        return response()->json([
            'as_of' => $asOf,
            'return_intention' => $period === null ? null : [
                'period_id' => $period->getKey(),
                'intention' => $period->intention,
                'effective_from' => $period->effective_from?->toDateString(),
                'effective_to' => $period->effective_to?->toDateString(),
            ],
        ]);
    }

    public function store(
        Request $request,
        Person $person,
        EmploymentRelationship $employmentRelationship,
        RecordReturnIntention $command,
        AuditedCommandExecutor $executor,
    ): JsonResponse {
        $this->ensureOwned($person, $employmentRelationship);

        $data = $request->validate([
            'intention' => ['required', 'string', Rule::in(ReturnIntention::values())],
            'effective_from' => ['required', 'date_format:Y-m-d'],
            'effective_to' => ['nullable', 'date_format:Y-m-d', 'after:effective_from'],
            'end_date' => ['prohibited'],
            'effective_until' => ['prohibited'],
            'duration_days' => ['prohibited'],
            'duration' => ['prohibited'],
            'is_temporary' => ['prohibited'],
            'return_date' => ['prohibited'],
            'expected_return_date' => ['prohibited'],
            'auto_return' => ['prohibited'],
        ]);

        $context = ResolveCommandContext::from($request);

        $spec = new AuditSpec(
            action: 'hr.return_intention_period.record',
            targetType: 'hr_return_intention_period',
            targetId: fn (ReturnIntentionPeriod $period) => $period->getKey(),
            changes: fn (ReturnIntentionPeriod $period) => [
                'employment_relationship_id' => $period->employment_relationship_id,
                'intention' => $period->intention,
                'effective_from' => $period->effective_from?->toDateString(),
                'effective_to' => $period->effective_to?->toDateString(),
            ],
            metadata: fn () => [],
        );

        return DB::transaction(function () use ($employmentRelationship, $context, $spec, $command, $executor, $data) {
            $period = $executor->run(
                $context,
                $spec,
                fn () => $command->handle($employmentRelationship, $data['intention'], $data['effective_from'], $data['effective_to'] ?? null),
            );

            return (new ReturnIntentionPeriodResource($period))->response()->setStatusCode(201);
        });
    }
}
