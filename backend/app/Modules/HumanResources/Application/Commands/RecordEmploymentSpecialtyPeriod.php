<?php

namespace App\Modules\HumanResources\Application\Commands;

use App\Modules\HumanResources\Domain\Exceptions\EmploymentRelationshipAlreadyEndedException;
use App\Modules\HumanResources\Domain\Exceptions\InvalidEmploymentSpecialtyException;
use App\Modules\HumanResources\Domain\Exceptions\InvalidEmploymentSpecialtyPeriodDateException;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\EmploymentRelationship;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\EmploymentSpecialtyPeriod;
use App\Modules\Platform\Infrastructure\Persistence\Postgres\PostgresErrorClassifier as Errors;
use App\Modules\Reference\Infrastructure\Persistence\Eloquent\Specialty;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;

/**
 * Records one employee specialty period — the first known specialty or a later specialty change —
 * for an Employment Relationship of either appointment type
 * (docs/employee-specialty-history-foundation-specification.md §S26.9, ADR-S26-001). Same
 * auto-close-on-insert discipline as S22's RecordEmploymentJobTitlePeriod: when the latest period
 * is still open it is TEMPORALLY CLOSED at exactly the new period's effective_from (its identity,
 * specialty and effective_from are preserved — temporal closure, not historical replacement), then
 * the new period is inserted open-ended. A latest period that is already closed is left untouched,
 * so a gap stays visible. Recording a specialty has no downstream consequence: it never touches the
 * relationship, PersonQualification (S23), job title (S22), the specialty catalog (S25) or any S06
 * cadre mapping.
 *
 * CA-S26-01: there is NO same-value special case — exactly the S20/S22 convention. Recording the
 * specialty the open period already carries, at a valid later effective_from, closes that period
 * and inserts a new adjacent period with the same specialty_id, as a normal successful record.
 *
 * The EmploymentRelationship is re-fetched fresh with lockForUpdate() as the first statement —
 * the S10–S22 discipline — serialising this command against EndEmploymentRelationship and any
 * other recording on the same relationship; the employment_specialty_periods_no_overlap EXCLUDE
 * constraint is the independent database-level backstop.
 */
final class RecordEmploymentSpecialtyPeriod
{
    /**
     * @throws EmploymentRelationshipAlreadyEndedException|InvalidEmploymentSpecialtyException
     * @throws InvalidEmploymentSpecialtyPeriodDateException
     */
    public function handle(
        EmploymentRelationship $relationship,
        Specialty $specialty,
        string $effectiveFrom,
    ): EmploymentSpecialtyPeriod {
        $freshRelationship = EmploymentRelationship::query()
            ->where('id', $relationship->getKey())
            ->lockForUpdate()
            ->firstOrFail();

        if ($freshRelationship->end_knowledge_state === 'KNOWN') {
            throw new EmploymentRelationshipAlreadyEndedException;
        }

        $freshSpecialty = Specialty::query()
            ->where('id', $specialty->getKey())
            ->first();

        if ($freshSpecialty === null || ! $freshSpecialty->is_active) {
            throw new InvalidEmploymentSpecialtyException;
        }

        $newFrom = Carbon::parse($effectiveFrom);

        // A specialty MAY start on the relationship's own effective_from but never before it (the
        // S22 rule). effective_from is immutable after CreateEmploymentRelationship, so this
        // application-level comparison carries no concurrency risk (S10 spec §7.3 disclosure).
        if ($newFrom->lt($freshRelationship->effective_from)) {
            throw new InvalidEmploymentSpecialtyPeriodDateException;
        }

        // Compared against the LATEST recorded period (open or closed), so a backdated period can
        // never be inserted before, or on top of, later history.
        $latestPeriod = EmploymentSpecialtyPeriod::query()
            ->where('employment_relationship_id', $freshRelationship->getKey())
            ->orderByDesc('effective_from')
            ->first();

        if ($latestPeriod !== null && $newFrom->lte($latestPeriod->effective_from)) {
            throw new InvalidEmploymentSpecialtyPeriodDateException;
        }

        if ($latestPeriod !== null && $latestPeriod->effective_to === null) {
            try {
                $latestPeriod->update(['effective_to' => $effectiveFrom]);
            } catch (QueryException $e) {
                if (Errors::isCheckViolation($e)) {
                    throw new InvalidEmploymentSpecialtyPeriodDateException;
                }

                throw $e;
            }
        }

        $period = new EmploymentSpecialtyPeriod([
            'employment_relationship_id' => $freshRelationship->getKey(),
            'specialty_id' => $freshSpecialty->getKey(),
            'effective_from' => $effectiveFrom,
            'effective_to' => null,
        ]);

        try {
            $period->save();
        } catch (QueryException $e) {
            if (Errors::isExclusionViolation($e) || Errors::isCheckViolation($e)) {
                throw new InvalidEmploymentSpecialtyPeriodDateException;
            }

            throw $e;
        }

        return $period->refresh();
    }
}
