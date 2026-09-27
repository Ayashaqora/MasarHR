<?php

namespace App\Modules\HumanResources\Presentation\Http\Controllers;

use App\Modules\Audit\Application\AuditedCommandExecutor;
use App\Modules\Audit\Domain\AuditSpec;
use App\Modules\HumanResources\Application\Commands\RecordEmploymentStatusPeriod;
use App\Modules\HumanResources\Application\Queries\ListEmploymentStatusPeriodsForRelationship;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\EmploymentRelationship;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\EmploymentStatusPeriod;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\Person;
use App\Modules\HumanResources\Presentation\Http\Resources\EmploymentStatusPeriodResource;
use App\Modules\Platform\Presentation\Http\Middleware\ResolveCommandContext;
use App\Modules\Reference\Infrastructure\Persistence\Eloquent\EmploymentStatusDetail;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * S10 Employment Status History administration surface (spec §15), nested under an existing
 * {person}/{employmentRelationship} — mirrors EmploymentRelationshipController's own nested-under-
 * {person} shape exactly. No route here ever creates or ends a relationship as a side effect of
 * anything other than the already-approved consequence rules in RecordEmploymentStatusPeriod
 * (spec §7).
 */
class EmploymentStatusPeriodController
{
    public function index(
        Person $person,
        EmploymentRelationship $employmentRelationship,
        ListEmploymentStatusPeriodsForRelationship $query,
    ): JsonResponse {
        if ($employmentRelationship->person_id !== $person->getKey()) {
            throw new NotFoundHttpException('Employment relationship not found for this person.');
        }

        return EmploymentStatusPeriodResource::collection($query($employmentRelationship))->response();
    }

    public function store(
        Request $request,
        Person $person,
        EmploymentRelationship $employmentRelationship,
        RecordEmploymentStatusPeriod $command,
        AuditedCommandExecutor $executor,
    ): JsonResponse {
        if ($employmentRelationship->person_id !== $person->getKey()) {
            throw new NotFoundHttpException('Employment relationship not found for this person.');
        }

        $data = $request->validate([
            'status_detail_code' => ['required', 'string', 'max:64'],
            'effective_from' => ['required', 'date'],
        ]);

        $statusDetail = EmploymentStatusDetail::query()->where('code', $data['status_detail_code'])->first();

        if ($statusDetail === null) {
            throw new NotFoundHttpException('Employment status detail not found.');
        }

        $context = ResolveCommandContext::from($request);

        // Captured before the command runs: whether this relationship was already ended going
        // into this request. Comparing against the fresh post-command state below (read inside
        // the same still-open transaction, per AuditedCommandExecutor::run()) is how the metadata
        // closure detects "the relationship was also closed as a consequence" (spec §14) without
        // RecordEmploymentStatusPeriod having to widen its own return type to carry that fact.
        $wasAlreadyEnded = $employmentRelationship->end_knowledge_state === 'KNOWN';

        $spec = new AuditSpec(
            action: 'hr.employment_status_period.record',
            targetType: 'hr_employment_status_period',
            targetId: fn (EmploymentStatusPeriod $period) => $period->getKey(),
            changes: fn (EmploymentStatusPeriod $period) => [
                'employment_relationship_id' => $period->employment_relationship_id,
                'status_detail_id' => $period->status_detail_id,
                'effective_from' => $period->effective_from?->toDateString(),
            ],
            metadata: function () use ($statusDetail, $employmentRelationship, $wasAlreadyEnded) {
                $metadata = ['status_detail_code' => $statusDetail->code];

                $freshRelationship = EmploymentRelationship::query()
                    ->where('id', $employmentRelationship->getKey())
                    ->first();

                $closedAsConsequence = ! $wasAlreadyEnded && $freshRelationship?->end_knowledge_state === 'KNOWN';

                if ($closedAsConsequence) {
                    $metadata['relationship_closed_as_consequence'] = true;
                    $metadata['ended_terminally'] = (bool) $freshRelationship->ended_terminally;
                }

                return $metadata;
            },
        );

        $period = $executor->run(
            $context,
            $spec,
            fn () => $command->handle(
                $person,
                $employmentRelationship,
                $statusDetail,
                $data['effective_from'],
            ),
        );

        return (new EmploymentStatusPeriodResource($period))->response()->setStatusCode(201);
    }
}
