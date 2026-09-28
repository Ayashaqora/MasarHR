<?php

namespace App\Modules\HumanResources\Presentation\Http\Controllers;

use App\Modules\Audit\Application\AuditedCommandExecutor;
use App\Modules\Audit\Domain\AuditSpec;
use App\Modules\HumanResources\Application\Commands\CreateEmploymentRelationship;
use App\Modules\HumanResources\Application\Commands\EndEmploymentRelationship;
use App\Modules\HumanResources\Application\Queries\ListEmploymentRelationshipsForPerson;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\EmploymentCategoryPeriod;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\EmploymentRelationship;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\EmploymentStatusPeriod;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\FullSecondmentPeriod;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\Person;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\WorkplaceAssignmentPeriod;
use App\Modules\HumanResources\Presentation\Http\Resources\EmploymentRelationshipResource;
use App\Modules\Platform\Presentation\Http\Middleware\ResolveCommandContext;
use App\Modules\Reference\Infrastructure\Persistence\Eloquent\EmploymentType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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

        // S15 (docs/employment-status-lifecycle-consequences-specification.md §17), TOCTOU-closing
        // shape mirrored exactly from TransferController::store()'s own established pattern: the
        // relationship row is locked FIRST, in an outer transaction, before the "before" snapshot
        // below is taken — an unlocked plain SELECT here would let a genuinely concurrent, non-
        // adversarial request (e.g. StartFullSecondment/EndFullSecondment on the same relationship)
        // commit in the gap between this read and EndEmploymentRelationship's own locked re-check,
        // producing a false full_secondment_closed_as_consequence/status_period_closed_as_consequence
        // claim in the audit log (a false positive or false negative) — an adversarial-review
        // finding on an earlier draft of this method, fixed here rather than left as a known gap.
        // $executor->run()'s own DB::transaction() call below becomes a savepoint within this outer
        // transaction (already-established-safe nesting, same as TransferController's own reuse of
        // AuditedCommandExecutor inside its own outer lock).
        return DB::transaction(function () use (
            $employmentRelationship, $context, $person, $command, $executor, $data,
        ) {
            EmploymentRelationship::query()->where('id', $employmentRelationship->getKey())->lockForUpdate()->firstOrFail();

            $hadOpenSecondment = FullSecondmentPeriod::query()
                ->where('employment_relationship_id', $employmentRelationship->getKey())
                ->whereNull('effective_to')
                ->exists();

            $hadOpenStatusPeriod = EmploymentStatusPeriod::query()
                ->where('employment_relationship_id', $employmentRelationship->getKey())
                ->whereNull('effective_to')
                ->exists();

            // S16 (docs/workplace-assignment-foundation-specification.md §S16.10): mirrors
            // $hadOpenSecondment/$stillOpenSecondment exactly, closing the same audit-completeness
            // gap for the newer consequence — caught here proactively (same class of gap the
            // TransferResource fix caught for S16's Transfer-side consequence) rather than left
            // silently unreported.
            $hadOpenAssignment = WorkplaceAssignmentPeriod::query()
                ->where('employment_relationship_id', $employmentRelationship->getKey())
                ->whereNull('effective_to')
                ->exists();

            // S20 (docs/employment-category-history-foundation-specification.md §S20.11/§S20.17):
            // mirrors $hadOpenAssignment/$stillOpenAssignment exactly for the newest consequence.
            $hadOpenCategoryPeriod = EmploymentCategoryPeriod::query()
                ->where('employment_relationship_id', $employmentRelationship->getKey())
                ->whereNull('effective_to')
                ->exists();

            $spec = new AuditSpec(
                action: 'hr.employment_relationship.end',
                targetType: 'hr_employment_relationship',
                targetId: fn () => $employmentRelationship->getKey(),
                changes: fn (EmploymentRelationship $ended) => [
                    'effective_to' => $ended->effective_to?->toDateString(),
                    'ended_terminally' => $ended->ended_terminally,
                ],
                metadata: function () use (
                    $employmentRelationship, $hadOpenSecondment, $hadOpenStatusPeriod, $hadOpenAssignment, $hadOpenCategoryPeriod,
                ) {
                    $metadata = [];

                    $stillOpenSecondment = FullSecondmentPeriod::query()
                        ->where('employment_relationship_id', $employmentRelationship->getKey())
                        ->whereNull('effective_to')
                        ->exists();

                    if ($hadOpenSecondment && ! $stillOpenSecondment) {
                        $metadata['full_secondment_closed_as_consequence'] = true;
                    }

                    $stillOpenStatusPeriod = EmploymentStatusPeriod::query()
                        ->where('employment_relationship_id', $employmentRelationship->getKey())
                        ->whereNull('effective_to')
                        ->exists();

                    if ($hadOpenStatusPeriod && ! $stillOpenStatusPeriod) {
                        $metadata['status_period_closed_as_consequence'] = true;
                    }

                    $stillOpenAssignment = WorkplaceAssignmentPeriod::query()
                        ->where('employment_relationship_id', $employmentRelationship->getKey())
                        ->whereNull('effective_to')
                        ->exists();

                    if ($hadOpenAssignment && ! $stillOpenAssignment) {
                        $metadata['workplace_assignment_closed_as_consequence'] = true;
                    }

                    $stillOpenCategoryPeriod = EmploymentCategoryPeriod::query()
                        ->where('employment_relationship_id', $employmentRelationship->getKey())
                        ->whereNull('effective_to')
                        ->exists();

                    if ($hadOpenCategoryPeriod && ! $stillOpenCategoryPeriod) {
                        $metadata['employment_category_period_closed_as_consequence'] = true;
                    }

                    return $metadata;
                },
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
        });
    }
}
