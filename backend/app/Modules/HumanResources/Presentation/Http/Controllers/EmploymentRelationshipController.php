<?php

namespace App\Modules\HumanResources\Presentation\Http\Controllers;

use App\Modules\Audit\Application\AuditedCommandExecutor;
use App\Modules\Audit\Domain\AuditSpec;
use App\Modules\HumanResources\Application\Commands\CreateEmploymentRelationship;
use App\Modules\HumanResources\Application\Commands\EndEmploymentRelationship;
use App\Modules\HumanResources\Application\Queries\ListEmploymentRelationshipsForPerson;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\EmploymentRelationship;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\Person;
use App\Modules\HumanResources\Presentation\Http\Resources\EmploymentRelationshipResource;
use App\Modules\Platform\Presentation\Http\Middleware\ResolveCommandContext;
use App\Modules\Reference\Infrastructure\Persistence\Eloquent\EmploymentType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * S09 Employment Relationship administration surface (spec §19), nested under an existing
 * {person} — mirrors the S08 OrganizationalScopeController's nested-under-{principal} shape
 * exactly. No route here ever creates a Person as a side effect (spec §13).
 */
class EmploymentRelationshipController
{
    public function index(Person $person, ListEmploymentRelationshipsForPerson $query): JsonResponse
    {
        return EmploymentRelationshipResource::collection($query($person))->response();
    }

    public function store(
        Request $request,
        Person $person,
        CreateEmploymentRelationship $command,
        AuditedCommandExecutor $executor,
    ): JsonResponse {
        $data = $request->validate([
            'employment_type_code' => ['required', 'string', 'in:permanent,contract'],
            'effective_from' => ['required', 'date'],
            'employee_number' => [
                'required_if:employment_type_code,permanent',
                'prohibited_if:employment_type_code,contract',
                'nullable',
                'string',
                'max:64',
            ],
        ]);

        $employmentType = EmploymentType::query()->where('code', $data['employment_type_code'])->first();

        if ($employmentType === null) {
            throw new NotFoundHttpException('Employment type not found.');
        }

        $context = ResolveCommandContext::from($request);

        $spec = new AuditSpec(
            action: 'hr.employment_relationship.create',
            targetType: 'hr_employment_relationship',
            targetId: fn (EmploymentRelationship $created) => $created->getKey(),
            changes: fn (EmploymentRelationship $created) => [
                'person_id' => $created->person_id,
                'employment_type_id' => $created->employment_type_id,
                'employee_number_scheme' => $created->employee_number_scheme,
                'effective_from' => $created->effective_from?->toDateString(),
            ],
            metadata: fn () => [],
        );

        $relationship = $executor->run(
            $context,
            $spec,
            fn (): EmploymentRelationship => $command->handle(
                $person,
                $employmentType,
                $data['effective_from'],
                $data['employee_number'] ?? null,
            ),
        );

        return (new EmploymentRelationshipResource($relationship))->response()->setStatusCode(201);
    }

    public function end(
        Request $request,
        Person $person,
        EmploymentRelationship $employmentRelationship,
        EndEmploymentRelationship $command,
        AuditedCommandExecutor $executor,
    ): JsonResponse {
        if ($employmentRelationship->person_id !== $person->getKey()) {
            throw new NotFoundHttpException('Employment relationship not found for this person.');
        }

        $data = $request->validate([
            'expected_version' => ['required', 'integer', 'min:1'],
            'effective_to' => ['required', 'date'],
            'is_terminal' => ['required', 'boolean'],
        ]);

        $context = ResolveCommandContext::from($request);

        $spec = new AuditSpec(
            action: 'hr.employment_relationship.end',
            targetType: 'hr_employment_relationship',
            targetId: fn () => $employmentRelationship->getKey(),
            changes: fn (EmploymentRelationship $ended) => [
                'effective_to' => $ended->effective_to?->toDateString(),
                'ended_terminally' => $ended->ended_terminally,
            ],
            metadata: fn () => [],
        );

        $ended = $executor->run(
            $context,
            $spec,
            fn (): EmploymentRelationship => $command->handle(
                $person,
                $employmentRelationship,
                (int) $data['expected_version'],
                $data['effective_to'],
                (bool) $data['is_terminal'],
            ),
        );

        return (new EmploymentRelationshipResource($ended))->response();
    }
}
