<?php

namespace App\Modules\HumanResources\Presentation\Http\Controllers;

use App\Modules\Audit\Application\AuditedCommandExecutor;
use App\Modules\Audit\Domain\AuditSpec;
use App\Modules\HumanResources\Application\Commands\RecordEmploymentJobTitlePeriod;
use App\Modules\HumanResources\Application\Queries\ListEmploymentJobTitlePeriodsForRelationship;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\EmploymentJobTitlePeriod;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\EmploymentRelationship;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\Person;
use App\Modules\HumanResources\Presentation\Http\Resources\EmploymentJobTitlePeriodResource;
use App\Modules\Platform\Presentation\Http\Middleware\ResolveCommandContext;
use App\Modules\Reference\Infrastructure\Persistence\Eloquent\JobTitle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * S22 Employment Job Title History surface
 * (docs/employment-job-title-history-foundation-specification.md §S22.14), nested under an
 * existing {person}/{employmentRelationship} — mirrors EmploymentContractPeriodController (S21):
 * plain RBAC via the route's permission: middleware (ADR-S22-001 §9), and the relationship row is
 * locked first so the "previous period" snapshot used for the temporal-closure audit metadata
 * cannot race a concurrent writer. Explicit record action only: no generic PATCH, no DELETE, no
 * end route, and no duplicate of the Reference module's /reference/job-titles administration.
 */
class EmploymentJobTitlePeriodController
{
    public function index(
        Person $person,
        EmploymentRelationship $employmentRelationship,
        ListEmploymentJobTitlePeriodsForRelationship $query,
    ): JsonResponse {
        $this->guardOwnership($person, $employmentRelationship);

        return EmploymentJobTitlePeriodResource::collection($query($employmentRelationship))->response();
    }

    public function store(
        Request $request,
        Person $person,
        EmploymentRelationship $employmentRelationship,
        RecordEmploymentJobTitlePeriod $command,
        AuditedCommandExecutor $executor,
    ): JsonResponse {
        $this->guardOwnership($person, $employmentRelationship);

        $data = $request->validate([
            'job_title_id' => ['required', 'uuid'],
            'effective_from' => ['required', 'date'],
        ]);

        // Existence only here (404, mirroring S16/S20/S21); the active-at-command-time rule is
        // enforced by RecordEmploymentJobTitlePeriod against a fresh re-fetch.
        $jobTitle = JobTitle::query()->find($data['job_title_id']);

        if ($jobTitle === null) {
            throw new NotFoundHttpException('Job title not found.');
        }

        $context = ResolveCommandContext::from($request);

        return DB::transaction(function () use ($employmentRelationship, $jobTitle, $context, $command, $executor, $data) {
            EmploymentRelationship::query()->where('id', $employmentRelationship->getKey())->lockForUpdate()->firstOrFail();

            $previous = EmploymentJobTitlePeriod::query()
                ->where('employment_relationship_id', $employmentRelationship->getKey())
                ->orderByDesc('effective_from')
                ->first();
            $previousEffectiveTo = $previous?->effective_to?->toDateString();

            $spec = new AuditSpec(
                action: 'hr.employment_job_title_period.record',
                targetType: 'hr_employment_job_title_period',
                targetId: fn (EmploymentJobTitlePeriod $period) => $period->getKey(),
                changes: fn (EmploymentJobTitlePeriod $period) => [
                    'employment_relationship_id' => $period->employment_relationship_id,
                    'job_title_id' => $period->job_title_id,
                    'effective_from' => $period->effective_from?->toDateString(),
                ],
                metadata: function () use ($jobTitle, $previous, $previousEffectiveTo) {
                    $metadata = ['job_title_code' => $jobTitle->code];

                    if ($previous !== null) {
                        $metadata['previous_period_id'] = $previous->getKey();

                        $previousNowTo = EmploymentJobTitlePeriod::query()->find($previous->getKey())?->effective_to?->toDateString();

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
                fn () => $command->handle($employmentRelationship, $jobTitle, $data['effective_from']),
            );

            return (new EmploymentJobTitlePeriodResource($period))->response()->setStatusCode(201);
        });
    }

    private function guardOwnership(Person $person, EmploymentRelationship $employmentRelationship): void
    {
        if ($employmentRelationship->person_id !== $person->getKey()) {
            throw new NotFoundHttpException('Employment relationship not found for this person.');
        }
    }
}
