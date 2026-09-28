<?php

namespace App\Modules\HumanResources\Presentation\Http\Controllers;

use App\Modules\Audit\Application\AuditedCommandExecutor;
use App\Modules\Audit\Domain\AuditSpec;
use App\Modules\HumanResources\Application\Commands\RecordPersonQualification;
use App\Modules\HumanResources\Application\Queries\ListPersonQualifications;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\Person;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\PersonQualification;
use App\Modules\HumanResources\Presentation\Http\Resources\PersonQualificationResource;
use App\Modules\Platform\Presentation\Http\Middleware\ResolveCommandContext;
use App\Modules\Reference\Infrastructure\Persistence\Eloquent\AcademicDegree;
use App\Modules\Reference\Infrastructure\Persistence\Eloquent\QualificationType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * S23 Person Qualification surface (docs/person-qualification-foundation-specification.md §S23.13),
 * nested under an existing {person} — mirrors PersonController's (S09) Person-level plain RBAC: the
 * route's permission: middleware is the whole gate (ADR-S23-001 §9). Explicit record action only:
 * no generic PATCH, no DELETE, no correction route, and no duplicate of the Reference module's own
 * /reference/academic-degrees and /reference/qualification-types administration.
 */
class PersonQualificationController
{
    public function index(Person $person, ListPersonQualifications $query): JsonResponse
    {
        return PersonQualificationResource::collection($query($person))->response();
    }

    public function store(
        Request $request,
        Person $person,
        RecordPersonQualification $command,
        AuditedCommandExecutor $executor,
    ): JsonResponse {
        $data = $request->validate([
            'academic_degree_id' => ['nullable', 'uuid', 'required_without:qualification_type_id'],
            'qualification_type_id' => ['nullable', 'uuid', 'required_without:academic_degree_id'],
        ]);

        // Existence only here (404, mirroring S16/S20–S22); the active-at-command-time rule is
        // enforced by RecordPersonQualification against a fresh re-fetch.
        $academicDegree = null;
        if (($data['academic_degree_id'] ?? null) !== null) {
            $academicDegree = AcademicDegree::query()->find($data['academic_degree_id'])
                ?? throw new NotFoundHttpException('Academic degree not found.');
        }

        $qualificationType = null;
        if (($data['qualification_type_id'] ?? null) !== null) {
            $qualificationType = QualificationType::query()->find($data['qualification_type_id'])
                ?? throw new NotFoundHttpException('Qualification type not found.');
        }

        $spec = new AuditSpec(
            action: 'hr.person_qualification.record',
            targetType: 'hr_person_qualification',
            targetId: fn (PersonQualification $qualification) => $qualification->getKey(),
            changes: fn (PersonQualification $qualification) => [
                'person_id' => $qualification->person_id,
                'academic_degree_id' => $qualification->academic_degree_id,
                'qualification_type_id' => $qualification->qualification_type_id,
            ],
            metadata: fn () => array_filter([
                'academic_degree_code' => $academicDegree?->code,
                'qualification_type_code' => $qualificationType?->code,
            ], fn ($value) => $value !== null),
        );

        $qualification = $executor->run(
            ResolveCommandContext::from($request),
            $spec,
            fn () => $command->handle($person, $academicDegree, $qualificationType),
        );

        return (new PersonQualificationResource($qualification))->response()->setStatusCode(201);
    }
}
