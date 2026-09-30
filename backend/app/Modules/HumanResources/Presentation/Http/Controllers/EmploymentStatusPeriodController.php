<?php

namespace App\Modules\HumanResources\Presentation\Http\Controllers;

use App\Modules\Audit\Application\AuditedCommandExecutor;
use App\Modules\Audit\Domain\AuditSpec;
use App\Modules\HumanResources\Application\Commands\RecordEmploymentStatusPeriod;
use App\Modules\HumanResources\Application\Commands\RelationshipEndMovementConsequences;
use App\Modules\HumanResources\Application\Queries\ListEmploymentStatusPeriodsForRelationship;
use App\Modules\HumanResources\Application\Queries\ResolveEffectiveEmploymentStatusAsOf;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\EmploymentCategoryPeriod;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\EmploymentContractPeriod;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\EmploymentJobTitlePeriod;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\EmploymentRelationship;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\EmploymentSpecialtyPeriod;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\EmploymentStatusPeriod;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\FullSecondmentPeriod;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\PartialSecondmentPeriod;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\Person;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\WorkSchedulePeriod;
use App\Modules\HumanResources\Presentation\Http\Resources\EffectiveEmploymentStatusResource;
use App\Modules\HumanResources\Presentation\Http\Resources\EmploymentStatusPeriodResource;
use App\Modules\Platform\Application\Clock\BusinessDateClock;
use App\Modules\Platform\Presentation\Http\Middleware\ResolveCommandContext;
use App\Modules\Reference\Infrastructure\Persistence\Eloquent\EmploymentStatusDetail;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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

    /** S32 (§S32.4/§S32.7): effective status on a date, persisted vs derived explicit. S33: as_of is optional (defaults to today's business date). */
    public function effective(
        Request $request,
        Person $person,
        EmploymentRelationship $employmentRelationship,
        ResolveEffectiveEmploymentStatusAsOf $resolver,
        BusinessDateClock $clock,
    ): JsonResponse {
        if ($employmentRelationship->person_id !== $person->getKey()) {
            throw new NotFoundHttpException('Employment relationship not found for this person.');
        }

        $data = $request->validate(['as_of' => ['nullable', 'date_format:Y-m-d']]);

        // S33: when no date is supplied the authoritative business date (the same BusinessDateClock
        // S31 uses) is applied server-side, so Employee360 never computes "today" on its own.
        $asOf = $data['as_of'] ?? $clock->today()->toDateString();

        return response()->json(EffectiveEmploymentStatusResource::toArray($asOf, $resolver($employmentRelationship, $asOf)));
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
            // S32 (§S32.7): explicit, never silently ignored; DATE-only.
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

        $statusDetail = EmploymentStatusDetail::query()->where('code', $data['status_detail_code'])->first();

        if ($statusDetail === null) {
            throw new NotFoundHttpException('Employment status detail not found.');
        }

        $context = ResolveCommandContext::from($request);

        // S15 (docs/employment-status-lifecycle-consequences-specification.md §17), TOCTOU-closing
        // shape mirrored exactly from TransferController::store()'s own established pattern: the
        // relationship row is locked FIRST, in an outer transaction, before either "before" snapshot
        // below is taken. An unlocked plain SELECT here (an earlier draft of this method) would let
        // a genuinely concurrent, non-adversarial request (e.g. StartFullSecondment/
        // EndFullSecondment on the same relationship) commit in the gap between this read and
        // RecordEmploymentStatusPeriod's own locked re-check, producing a false
        // full_secondment_closed_as_consequence claim in the audit log (a false positive or false
        // negative) — an adversarial-review finding, fixed here rather than left as a known gap.
        // $executor->run()'s own DB::transaction() call below becomes a savepoint within this outer
        // transaction (already-established-safe nesting, same as TransferController's own reuse of
        // AuditedCommandExecutor inside its own outer lock).
        return DB::transaction(function () use (
            $employmentRelationship, $context, $person, $statusDetail, $command, $executor, $data,
        ) {
            EmploymentRelationship::query()->where('id', $employmentRelationship->getKey())->lockForUpdate()->firstOrFail();

            // Whether this relationship was already ended going into this request. Comparing
            // against the fresh post-command state below (read inside the same still-open
            // transaction, per AuditedCommandExecutor::run()) is how the metadata closure detects
            // "the relationship was also closed as a consequence" (spec §14) without
            // RecordEmploymentStatusPeriod having to widen its own return type to carry that fact.
            $wasAlreadyEnded = $employmentRelationship->end_knowledge_state === 'KNOWN';

            // S15 §17: same reasoning as $wasAlreadyEnded above — a Full Secondment can only be
            // closed as an S15 consequence of THIS call when the relationship itself is also
            // closed as a consequence this call (§8.1), so this is compared inside that same
            // branch below.
            // S35: same movement consequence snapshot as the direct end (one shared implementation), taken
            // under the same relationship lock; the end date of a status-triggered end is effective_from.
            $movementSnapshot = app(RelationshipEndMovementConsequences::class)->snapshot($employmentRelationship->getKey(), $data['effective_from']);

            $hadOpenSecondment = FullSecondmentPeriod::query()
                ->where('employment_relationship_id', $employmentRelationship->getKey())
                ->whereNull('effective_to')
                ->exists();

            // S20 CA-01/CA-02 (docs/employment-category-history-foundation-specification.md
            // §S20.17): same before/after snapshot as $hadOpenSecondment, taken under the same
            // relationship lock, so a status-triggered termination that also closes an open
            // Employment Category period exposes that consequence in THIS (the triggering)
            // audit entry — no separate audit event.
            $hadOpenCategoryPeriod = EmploymentCategoryPeriod::query()
                ->where('employment_relationship_id', $employmentRelationship->getKey())
                ->whereNull('effective_to')
                ->exists();

            // S21 (docs/employment-contract-foundation-specification.md §S21.11/§S21.18): whether a
            // contract period's actual validity still extends past the requested end date — if the
            // termination closes it, that consequence is exposed in THIS entry's metadata.
            $hadContractBeyondEnd = EmploymentContractPeriod::query()
                ->where('employment_relationship_id', $employmentRelationship->getKey())
                ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>', $data['effective_from']))
                ->exists();

            // S22 (docs/employment-job-title-history-foundation-specification.md §S22.11/§S22.16): same
            // locked before/after snapshot as the S20 category flag.
            $hadOpenJobTitlePeriod = EmploymentJobTitlePeriod::query()
                ->where('employment_relationship_id', $employmentRelationship->getKey())
                ->whereNull('effective_to')
                ->exists();

            // S26 (docs/employee-specialty-history-foundation-specification.md §S26.11): same locked
            // before/after snapshot as the S22 job title flag.
            $hadOpenSpecialtyPeriod = EmploymentSpecialtyPeriod::query()
                ->where('employment_relationship_id', $employmentRelationship->getKey())
                ->whereNull('effective_to')
                ->exists();

            // S29 (docs/work-schedule-foundation-specification.md §S29.10): same locked before/after
            // snapshot as the S26 specialty flag.
            $hadOpenWorkSchedulePeriod = WorkSchedulePeriod::query()
                ->where('employment_relationship_id', $employmentRelationship->getKey())
                ->whereNull('effective_to')
                ->exists();

            // S30 (docs/partial-secondment-foundation-specification.md §S30.16): partial secondments
            // still in force at the end date, snapshotted under the same lock.
            $partialsBeyondEnd = PartialSecondmentPeriod::query()
                ->where('employment_relationship_id', $employmentRelationship->getKey())
                ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>', $data['effective_from']))
                ->pluck('id')
                ->all();

            $spec = new AuditSpec(
                action: 'hr.employment_status_period.record',
                targetType: 'hr_employment_status_period',
                targetId: fn (EmploymentStatusPeriod $period) => $period->getKey(),
                changes: fn (EmploymentStatusPeriod $period) => [
                    'employment_relationship_id' => $period->employment_relationship_id,
                    'status_detail_id' => $period->status_detail_id,
                    'effective_from' => $period->effective_from?->toDateString(),
                    'effective_to' => $period->effective_to?->toDateString(),
                ],
                metadata: function () use ($statusDetail, $employmentRelationship, $wasAlreadyEnded, $hadOpenSecondment, $hadOpenCategoryPeriod, $hadContractBeyondEnd, $data, $hadOpenJobTitlePeriod, $hadOpenSpecialtyPeriod, $hadOpenWorkSchedulePeriod, $partialsBeyondEnd, $movementSnapshot) {
                    $metadata = ['status_detail_code' => $statusDetail->code];

                    $freshRelationship = EmploymentRelationship::query()
                        ->where('id', $employmentRelationship->getKey())
                        ->first();

                    $closedAsConsequence = ! $wasAlreadyEnded && $freshRelationship?->end_knowledge_state === 'KNOWN';

                    if ($closedAsConsequence) {
                        $metadata['relationship_closed_as_consequence'] = true;
                        $metadata['ended_terminally'] = (bool) $freshRelationship->ended_terminally;

                        // S15 §8.1/§17: the relationship-ending consequence above may itself have
                        // triggered a second, in-process consequence — EndEmploymentRelationship
                        // closing an open Full Secondment — surfaced the same before/after way,
                        // never by widening RecordEmploymentStatusPeriod's own return type.
                        $stillOpenSecondment = FullSecondmentPeriod::query()
                            ->where('employment_relationship_id', $employmentRelationship->getKey())
                            ->whereNull('effective_to')
                            ->exists();

                        if ($hadOpenSecondment && ! $stillOpenSecondment) {
                            $metadata['full_secondment_closed_as_consequence'] = true;
                        }

                        $stillOpenCategoryPeriod = EmploymentCategoryPeriod::query()
                            ->where('employment_relationship_id', $employmentRelationship->getKey())
                            ->whereNull('effective_to')
                            ->exists();

                        if ($hadOpenCategoryPeriod && ! $stillOpenCategoryPeriod) {
                            $metadata['employment_category_period_closed_as_consequence'] = true;
                        }

                        $stillContractBeyondEnd = EmploymentContractPeriod::query()
                            ->where('employment_relationship_id', $employmentRelationship->getKey())
                            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>', $data['effective_from']))
                            ->exists();

                        if ($hadContractBeyondEnd && ! $stillContractBeyondEnd) {
                            $metadata['employment_contract_period_closed_as_consequence'] = true;
                        }

                        $stillOpenJobTitlePeriod = EmploymentJobTitlePeriod::query()
                            ->where('employment_relationship_id', $employmentRelationship->getKey())
                            ->whereNull('effective_to')
                            ->exists();

                        if ($hadOpenJobTitlePeriod && ! $stillOpenJobTitlePeriod) {
                            $metadata['employment_job_title_period_closed_as_consequence'] = true;
                        }

                        $stillOpenSpecialtyPeriod = EmploymentSpecialtyPeriod::query()
                            ->where('employment_relationship_id', $employmentRelationship->getKey())
                            ->whereNull('effective_to')
                            ->exists();

                        if ($hadOpenSpecialtyPeriod && ! $stillOpenSpecialtyPeriod) {
                            $metadata['employment_specialty_period_closed_as_consequence'] = true;
                        }

                        $stillOpenWorkSchedulePeriod = WorkSchedulePeriod::query()
                            ->where('employment_relationship_id', $employmentRelationship->getKey())
                            ->whereNull('effective_to')
                            ->exists();

                        if ($hadOpenWorkSchedulePeriod && ! $stillOpenWorkSchedulePeriod) {
                            $metadata['work_schedule_period_closed_as_consequence'] = true;
                        }

                        $closedPartials = $partialsBeyondEnd === [] ? [] : PartialSecondmentPeriod::query()
                            ->whereIn('id', $partialsBeyondEnd)
                            ->where('effective_to', $data['effective_from'])
                            ->orderBy('id')
                            ->pluck('id')
                            ->all();

                        if ($closedPartials !== []) {
                            $metadata['partial_secondment_closed_as_consequence'] = true;
                            $metadata['closed_partial_secondment_period_ids'] = $closedPartials;
                        }

                        // S35: identical movement consequence evidence to the direct relationship end.
                        $metadata += app(RelationshipEndMovementConsequences::class)->metadata($employmentRelationship->getKey(), $movementSnapshot);
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
                    $data['effective_to'] ?? null,
                ),
            );

            return (new EmploymentStatusPeriodResource($period))->response()->setStatusCode(201);
        });
    }
}
