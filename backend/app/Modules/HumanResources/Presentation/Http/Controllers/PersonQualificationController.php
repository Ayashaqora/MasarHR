<?php

namespace App\Modules\HumanResources\Presentation\Http\Controllers;

use App\Modules\Audit\Application\AuditedCommandExecutor;
use App\Modules\Audit\Domain\AuditSpec;
use App\Modules\HumanResources\Application\Commands\CorrectPersonQualification;
use App\Modules\HumanResources\Application\Commands\DesignateQualificationAsPrimary;
use App\Modules\HumanResources\Application\Commands\PersonQualificationCorrection;
use App\Modules\HumanResources\Application\Commands\PersonQualificationRecording;
use App\Modules\HumanResources\Application\Commands\PrimaryQualificationDesignation;
use App\Modules\HumanResources\Application\Commands\RecordPersonQualification;
use App\Modules\HumanResources\Application\Queries\BuildPersonPrimaryQualificationHistory;
use App\Modules\HumanResources\Application\Queries\ListPersonQualifications;
use App\Modules\HumanResources\Application\Queries\ListPersonQualificationVersions;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\Person;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\PersonQualification;
use App\Modules\HumanResources\Presentation\Http\Resources\PersonQualificationResource;
use App\Modules\HumanResources\Presentation\Http\Resources\PersonQualificationVersionResource;
use App\Modules\HumanResources\Presentation\Http\Resources\PrimaryQualificationHistoryEventResource;
use App\Modules\Platform\Presentation\Http\Middleware\ResolveCommandContext;
use App\Modules\Reference\Infrastructure\Persistence\Eloquent\AcademicDegree;
use App\Modules\Reference\Infrastructure\Persistence\Eloquent\QualificationType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * S23 Person Qualification surface (docs/person-qualification-foundation-specification.md §S23.13),
 * nested under an existing {person} — mirrors PersonController's (S09) Person-level plain RBAC: the
 * route's permission: middleware is the whole gate (ADR-S23-001 §9).
 *
 * S48 (docs/person-qualification-history-foundation-specification.md §S48.14) adds three read/write
 * surfaces on the same qualification identity: the version history, the correction command, and
 * the Primary-designation history — all still nested under the same Person-level plain RBAC, each
 * gated by its own dedicated permission.
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
            'obtained_on' => ['nullable', 'date_format:Y-m-d'],
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

        $context = ResolveCommandContext::from($request);

        $spec = new AuditSpec(
            action: 'hr.person_qualification.record',
            targetType: 'hr_person_qualification',
            targetId: fn (PersonQualificationRecording $recording) => $recording->qualification->getKey(),
            changes: fn (PersonQualificationRecording $recording) => [
                'person_id' => $recording->qualification->person_id,
                'academic_degree_id' => $recording->version->academic_degree_id,
                'qualification_type_id' => $recording->version->qualification_type_id,
                'is_primary' => (bool) $recording->qualification->is_primary,
            ],
            metadata: fn () => array_filter([
                'academic_degree_code' => $academicDegree?->code,
                'qualification_type_code' => $qualificationType?->code,
            ], fn ($value) => $value !== null),
        );

        $recording = $executor->run(
            $context,
            $spec,
            fn () => $command->handle(
                $person, $academicDegree, $qualificationType,
                $data['obtained_on'] ?? null, $context->actor->principalId,
            ),
        );

        $recording->qualification->setRelation('currentVersion', $recording->version);

        return (new PersonQualificationResource($recording->qualification))->response()->setStatusCode(201);
    }

    /**
     * S41 (R1-D49): POST /persons/{person}/qualifications/{personQualification}/designate-primary — the explicit operation that
     * makes one of the Person's qualifications Primary. No generic PATCH. A qualification of another Person is 404; an
     * already-Primary target is an idempotent 200 (audited, state unchanged).
     */
    public function designatePrimary(
        Request $request,
        Person $person,
        PersonQualification $personQualification,
        DesignateQualificationAsPrimary $command,
        AuditedCommandExecutor $executor,
    ): JsonResponse {
        $this->assertBelongsToPerson($person, $personQualification);

        $spec = new AuditSpec(
            action: 'hr.person_qualification.designate_primary',
            targetType: 'hr_person_qualification',
            targetId: fn (PrimaryQualificationDesignation $result) => $result->qualification->getKey(),
            changes: fn (PrimaryQualificationDesignation $result) => [
                'person_id' => $result->qualification->person_id,
                'previous_primary_qualification_id' => $result->previousPrimaryQualificationId,
                'new_primary_qualification_id' => $result->qualification->getKey(),
            ],
            metadata: fn (PrimaryQualificationDesignation $result) => ['state_changed' => $result->changed],
        );

        $result = $executor->run(
            ResolveCommandContext::from($request),
            $spec,
            fn () => $command->handle($person, $personQualification),
        );

        $result->qualification->load('currentVersion');

        return (new PersonQualificationResource($result->qualification))->response();
    }

    /**
     * S48: GET .../versions — every version of this qualification, oldest first (§S48.14, D37).
     */
    public function versions(
        Request $request,
        Person $person,
        PersonQualification $personQualification,
        ListPersonQualificationVersions $query,
    ): JsonResponse {
        $this->assertBelongsToPerson($person, $personQualification);

        $data = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $paginator = $query($personQualification, (int) ($data['page'] ?? 1), (int) ($data['per_page'] ?? 25));

        return PersonQualificationVersionResource::collection($paginator)->response();
    }

    /**
     * S48: POST .../corrections — records one correction (§S48.5/§S48.14). Request body frozen by
     * D26: expected_version, academic_degree_id, qualification_type_id, obtained_on are all
     * required KEYS (nullable for the latter three) — never silently defaulted.
     */
    public function correct(
        Request $request,
        Person $person,
        PersonQualification $personQualification,
        CorrectPersonQualification $command,
        AuditedCommandExecutor $executor,
    ): JsonResponse {
        $this->assertBelongsToPerson($person, $personQualification);

        $data = $request->validate([
            'expected_version' => ['required', 'integer', 'min:1'],
            'academic_degree_id' => ['present', 'nullable', 'uuid'],
            'qualification_type_id' => ['present', 'nullable', 'uuid'],
            'obtained_on' => ['present', 'nullable', 'date_format:Y-m-d'],
            'reason' => ['required', 'string', function (string $attribute, mixed $value, callable $fail): void {
                $trimmed = trim((string) $value);
                if ($trimmed === '' || mb_strlen($trimmed) > 2000) {
                    $fail('reason must be 1 to 2000 characters once trimmed.');
                }
            }],
        ]);

        $academicDegree = null;
        if ($data['academic_degree_id'] !== null) {
            $academicDegree = AcademicDegree::query()->find($data['academic_degree_id'])
                ?? throw new NotFoundHttpException('Academic degree not found.');
        }

        $qualificationType = null;
        if ($data['qualification_type_id'] !== null) {
            $qualificationType = QualificationType::query()->find($data['qualification_type_id'])
                ?? throw new NotFoundHttpException('Qualification type not found.');
        }

        $context = ResolveCommandContext::from($request);
        $reason = trim($data['reason']);

        $spec = new AuditSpec(
            action: 'hr.person_qualification.correction.record',
            targetType: 'hr_person_qualification',
            targetId: fn (PersonQualificationCorrection $correction) => $correction->qualification->getKey(),
            changes: fn (PersonQualificationCorrection $correction) => [
                'person_id' => $correction->qualification->person_id,
                'previous' => [
                    'academic_degree_id' => $correction->previousVersion->academic_degree_id,
                    'qualification_type_id' => $correction->previousVersion->qualification_type_id,
                    'obtained_on' => $correction->previousVersion->obtained_on?->toDateString(),
                ],
                'new' => [
                    'academic_degree_id' => $correction->newVersion->academic_degree_id,
                    'qualification_type_id' => $correction->newVersion->qualification_type_id,
                    'obtained_on' => $correction->newVersion->obtained_on?->toDateString(),
                ],
                'reason' => $correction->newVersion->reason,
            ],
            metadata: fn () => array_filter([
                'academic_degree_code' => $academicDegree?->code,
                'qualification_type_code' => $qualificationType?->code,
            ], fn ($value) => $value !== null),
        );

        $correction = $executor->run(
            $context,
            $spec,
            fn () => $command->handle(
                $person, $personQualification, (int) $data['expected_version'],
                $academicDegree, $qualificationType, $data['obtained_on'], $reason,
                $context->actor->principalId,
            ),
        );

        return (new PersonQualificationVersionResource($correction->newVersion))->response()->setStatusCode(201);
    }

    /**
     * S48: GET /persons/{person}/qualifications/primary-history — Primary-designation history,
     * sourced from the audit trail (§S48.10/§S48.14). `evidence_completeness` sits alongside the
     * paginated `events` envelope, not inside it, and is identical on every page (D35).
     */
    public function primaryHistory(
        Request $request,
        Person $person,
        BuildPersonPrimaryQualificationHistory $query,
    ): JsonResponse {
        $data = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $page = (int) ($data['page'] ?? 1);
        $perPage = (int) ($data['per_page'] ?? 25);

        $history = $query($person);

        $paginator = new LengthAwarePaginator(
            (new Collection($history->events))->forPage($page, $perPage)->values(),
            count($history->events),
            $perPage,
            $page,
            ['path' => $request->url(), 'query' => $request->query()],
        );

        $body = PrimaryQualificationHistoryEventResource::collection($paginator)->response()->getData(true);
        $body['events'] = $body['data'];
        unset($body['data']);
        $body['evidence_completeness'] = ['gaps' => $history->gaps];

        return response()->json($body);
    }

    private function assertBelongsToPerson(Person $person, PersonQualification $personQualification): void
    {
        if ($personQualification->person_id !== $person->getKey()) {
            throw new NotFoundHttpException('Qualification not found for this person.');
        }
    }
}
