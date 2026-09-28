<?php

namespace App\Modules\HumanResources\Presentation\Http\Controllers;

use App\Modules\Audit\Application\AuditedCommandExecutor;
use App\Modules\Audit\Domain\AuditSpec;
use App\Modules\HumanResources\Application\Commands\RecordEmploymentSpecialtyPeriod;
use App\Modules\HumanResources\Application\Queries\ListEmploymentSpecialtyPeriodsForRelationship;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\EmploymentRelationship;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\EmploymentSpecialtyPeriod;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\Person;
use App\Modules\HumanResources\Presentation\Http\Resources\EmploymentSpecialtyPeriodResource;
use App\Modules\Platform\Presentation\Http\Middleware\ResolveCommandContext;
use App\Modules\Reference\Infrastructure\Persistence\Eloquent\Specialty;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * S26 Employee Specialty History surface
 * (docs/employee-specialty-history-foundation-specification.md §S26.14), nested under an existing
 * {person}/{employmentRelationship} — mirrors EmploymentJobTitlePeriodController (S22): plain RBAC
 * via the route's permission: middleware, and the relationship row is locked first so the
 * "previous period" snapshot used for the temporal-closure audit metadata cannot race a concurrent
 * writer. Explicit record action only: no generic PATCH, no DELETE, no end or correction route, and
 * no duplicate of the S25 /reference/specialties catalog administration.
 */
class EmploymentSpecialtyPeriodController
{
    public function index(
        Person $person,
        EmploymentRelationship $employmentRelationship,
        ListEmploymentSpecialtyPeriodsForRelationship $query,
    ): JsonResponse {
        $this->guardOwnership($person, $employmentRelationship);

        return EmploymentSpecialtyPeriodResource::collection($query($employmentRelationship))->response();
    }

    public function store(
        Request $request,
        Person $person,
        EmploymentRelationship $employmentRelationship,
        RecordEmploymentSpecialtyPeriod $command,
        AuditedCommandExecutor $executor,
    ): JsonResponse {
        $this->guardOwnership($person, $employmentRelationship);

        $data = $request->validate([
            'specialty_id' => ['required', 'uuid'],
            'effective_from' => ['required', 'date'],
        ]);

        // Existence only here (404, mirroring S16/S20/S21/S22); the active-at-command-time rule is
        // enforced by RecordEmploymentSpecialtyPeriod against a fresh re-fetch.
        $specialty = Specialty::query()->find($data['specialty_id']);

        if ($specialty === null) {
            throw new NotFoundHttpException('Specialty not found.');
        }

        $context = ResolveCommandContext::from($request);

        return DB::transaction(function () use ($employmentRelationship, $specialty, $context, $command, $executor, $data) {
            EmploymentRelationship::query()->where('id', $employmentRelationship->getKey())->lockForUpdate()->firstOrFail();

            $previous = EmploymentSpecialtyPeriod::query()
                ->where('employment_relationship_id', $employmentRelationship->getKey())
                ->orderByDesc('effective_from')
                ->first();
            $previousEffectiveTo = $previous?->effective_to?->toDateString();

            $spec = new AuditSpec(
                action: 'hr.employment_specialty_period.record',
                targetType: 'hr_employment_specialty_period',
                targetId: fn (EmploymentSpecialtyPeriod $period) => $period->getKey(),
                changes: fn (EmploymentSpecialtyPeriod $period) => [
                    'employment_relationship_id' => $period->employment_relationship_id,
                    'specialty_id' => $period->specialty_id,
                    'effective_from' => $period->effective_from?->toDateString(),
                ],
                metadata: function () use ($specialty, $previous, $previousEffectiveTo) {
                    $metadata = ['specialty_code' => $specialty->code];

                    if ($previous !== null) {
                        $metadata['previous_period_id'] = $previous->getKey();

                        $previousNowTo = EmploymentSpecialtyPeriod::query()->find($previous->getKey())?->effective_to?->toDateString();

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
                fn () => $command->handle($employmentRelationship, $specialty, $data['effective_from']),
            );

            return (new EmploymentSpecialtyPeriodResource($period))->response()->setStatusCode(201);
        });
    }

    private function guardOwnership(Person $person, EmploymentRelationship $employmentRelationship): void
    {
        if ($employmentRelationship->person_id !== $person->getKey()) {
            throw new NotFoundHttpException('Employment relationship not found for this person.');
        }
    }
}
