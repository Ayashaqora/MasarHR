<?php

namespace App\Modules\HumanResources\Presentation\Http\Controllers;

use App\Modules\Audit\Application\AuditedCommandExecutor;
use App\Modules\Audit\Domain\AuditSpec;
use App\Modules\HumanResources\Application\Commands\RecordEmploymentCategoryPeriod;
use App\Modules\HumanResources\Application\Queries\ListEmploymentCategoryPeriodsForRelationship;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\EmploymentCategoryPeriod;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\EmploymentRelationship;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\Person;
use App\Modules\HumanResources\Presentation\Http\Resources\EmploymentCategoryPeriodResource;
use App\Modules\Platform\Presentation\Http\Middleware\ResolveCommandContext;
use App\Modules\Reference\Infrastructure\Persistence\Eloquent\EmploymentCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * S20 Employment Category History surface
 * (docs/employment-category-history-foundation-specification.md §S20.13), nested under an
 * existing {person}/{employmentRelationship} — mirrors EmploymentStatusPeriodController's (S10)
 * nested shape and its plain-RBAC authorization exactly (the route's permission: middleware is the
 * whole gate; no S08 organizational-scope composition, ADR-S20-001 §8). Explicit record action
 * only: no generic PATCH, no DELETE, no end route, and no duplicate of the Reference module's own
 * /reference/employment-categories catalog administration.
 */
class EmploymentCategoryPeriodController
{
    public function index(
        Person $person,
        EmploymentRelationship $employmentRelationship,
        ListEmploymentCategoryPeriodsForRelationship $query,
    ): JsonResponse {
        $this->guardOwnership($person, $employmentRelationship);

        return EmploymentCategoryPeriodResource::collection($query($employmentRelationship))->response();
    }

    public function store(
        Request $request,
        Person $person,
        EmploymentRelationship $employmentRelationship,
        RecordEmploymentCategoryPeriod $command,
        AuditedCommandExecutor $executor,
    ): JsonResponse {
        $this->guardOwnership($person, $employmentRelationship);

        $data = $request->validate([
            'employment_category_id' => ['required', 'uuid'],
            'effective_from' => ['required', 'date'],
        ]);

        // Existence only here (404, mirroring S16's decision_type_id lookup); the active-at-
        // command-time rule is enforced by RecordEmploymentCategoryPeriod against a fresh re-fetch
        // inside the audited transaction (422 InvalidEmploymentCategoryException).
        $category = EmploymentCategory::query()->find($data['employment_category_id']);

        if ($category === null) {
            throw new NotFoundHttpException('Employment category not found.');
        }

        $context = ResolveCommandContext::from($request);

        $spec = new AuditSpec(
            action: 'hr.employment_category_period.record',
            targetType: 'hr_employment_category_period',
            targetId: fn (EmploymentCategoryPeriod $period) => $period->getKey(),
            changes: fn (EmploymentCategoryPeriod $period) => [
                'employment_relationship_id' => $period->employment_relationship_id,
                'employment_category_id' => $period->employment_category_id,
                'effective_from' => $period->effective_from?->toDateString(),
            ],
            metadata: fn () => ['employment_category_code' => $category->code],
        );

        $period = $executor->run(
            $context,
            $spec,
            fn () => $command->handle($employmentRelationship, $category, $data['effective_from']),
        );

        return (new EmploymentCategoryPeriodResource($period))->response()->setStatusCode(201);
    }

    private function guardOwnership(Person $person, EmploymentRelationship $employmentRelationship): void
    {
        if ($employmentRelationship->person_id !== $person->getKey()) {
            throw new NotFoundHttpException('Employment relationship not found for this person.');
        }
    }
}
