<?php

namespace App\Modules\HumanResources\Application\Queries\Reporting;

use App\Modules\HumanResources\Domain\MonthInterval;
use App\Modules\HumanResources\Domain\MonthlyDimensionSegmentation;
use App\Modules\HumanResources\Domain\MonthlyStatusSegmentation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * R4 Monthly Employment Status report (docs/employment-status-report-foundation-specification.md). INTERNAL, READ-ONLY. Pipeline: the
 * canonical S37 monthly population is computed ONCE and consumed COMPLETELY (no duty-classification filter); its status segments are
 * authoritative (EXPLICIT on_duty / DERIVED_ON_DUTY => ON_DUTY, an EXPLICIT temporary status => that status, UNRESOLVED => INDETERMINATE) and
 * are never rebuilt here. R4 adds only what S37 does not carry: the Person identity, the Return Intention periods and — for the
 * terminal events — the behavior of the ending status. Terminal events are a SEPARATE grain (ES-D56): a KNOWN end dated in
 * [month_start, next_month_start) belongs to the month even when the ended relationship does not overlap S37's monthly population (an end
 * dated exactly month_start); those supplemental events come from one bounded batch, are deduplicated by relationship id, never add a Person
 * to Overall Persons and carry no status timeline or Return Intention. The canonical records plus those events are the ONLY source of every
 * official section (EmploymentStatusSections). No population or duty engine of its own, no schema change.
 *
 * Query strategy: a CONSTANT number of statements, independent of population size — S37 (8) and the R4 batches: (1) Person identity,
 * (2) the Return Intention periods of every represented relationship, (3) the supplemental terminal events (KNOWN ends in the month not
 * represented through S37, with identity, type and the status beginning at the end date), (4) the effective-dated behavior of the ending
 * statuses found at a terminal event (skipped when there is none). No per-Person, per-relationship, per-segment or per-period query.
 */
final class BuildEmploymentStatusReportResult
{
    public function __construct(private readonly ListMonthlyReportingPopulation $population) {}

    public function __invoke(string|Carbon $monthStart): EmploymentStatusReportResult
    {
        $month = MonthInterval::of($monthStart);
        $monthEnd = Carbon::createFromFormat('!Y-m-d', $month->next)->subDay()->toDateString();

        $canonical = ($this->population)($month->start); // S37, exactly once

        $personIds = [];
        $relationshipIds = [];
        $reasonDetailIds = [];
        foreach ($canonical->persons as $person) {
            $personIds[] = $person->personId;
            foreach ($person->relationships as $segment) {
                $relationshipIds[] = $segment->employmentRelationshipId;
                if ($this->terminalEventInMonth($month, $segment) && $segment->relationshipEndReason !== null) {
                    $reasonDetailIds[$segment->relationshipEndReason['status_detail_id']] = true;
                }
            }
        }

        $identity = [];
        $intentions = [];
        if ($personIds !== []) {
            foreach (DB::select(
                'SELECT p.id, p.national_id, p.full_name_ar FROM hr.persons p WHERE p.id = ANY(CAST(? AS uuid[]))',
                ['{'.implode(',', $personIds).'}'],
            ) as $row) {
                $identity[$row->id] = $row;
            }

            foreach (DB::select(
                'SELECT ri.id, ri.employment_relationship_id, ri.intention, ri.effective_from, ri.effective_to
                 FROM hr.return_intention_periods ri
                 WHERE ri.effective_from < ? AND (ri.effective_to IS NULL OR ri.effective_to > ?) AND ri.employment_relationship_id = ANY(CAST(? AS uuid[]))
                 ORDER BY ri.employment_relationship_id, ri.effective_from, ri.id',
                [$month->next, $month->start, '{'.implode(',', $relationshipIds).'}'],
            ) as $row) {
                $intentions[$row->employment_relationship_id][] = (array) $row;
            }
        }

        // ES-D56/ES-D57: KNOWN ends dated in [month_start, next_month_start) that S37's overlap rule did not represent (an end dated exactly month_start).
        $supplemental = DB::select(
            'SELECT r.id AS employment_relationship_id, r.person_id, r.effective_to, r.ended_terminally, et.code AS employment_type_code,
                    p.national_id, p.full_name_ar, sp.id AS status_period_id, sp.status_detail_id, sd.code AS status_code
             FROM hr.employment_relationships r
             JOIN hr.persons p ON p.id = r.person_id
             JOIN ref.employment_types et ON et.id = r.employment_type_id
             LEFT JOIN hr.employment_status_periods sp ON sp.employment_relationship_id = r.id AND sp.effective_from = r.effective_to
             LEFT JOIN ref.employment_status_details sd ON sd.id = sp.status_detail_id
             WHERE r.end_knowledge_state = \'KNOWN\' AND r.effective_to >= ? AND r.effective_to < ? AND r.id <> ALL(CAST(? AS uuid[]))
             ORDER BY r.person_id, r.effective_to, r.id',
            [$month->start, $month->next, '{'.implode(',', $relationshipIds).'}'],
        );
        $supplemental = array_values(array_filter($supplemental, fn (object $row) => ! in_array($row->employment_relationship_id, $relationshipIds, true)));
        foreach ($supplemental as $row) {
            if ($row->status_detail_id !== null) {
                $reasonDetailIds[$row->status_detail_id] = true;
            }
        }

        $behaviors = [];
        if ($reasonDetailIds !== []) {
            foreach (DB::select(
                'SELECT b.status_detail_id, b.effective_from, b.effective_to, b.is_relationship_ending, b.is_terminal
                 FROM ref.employment_status_detail_behaviors b WHERE b.status_detail_id = ANY(CAST(? AS uuid[]))
                 ORDER BY b.status_detail_id, b.effective_from',
                ['{'.implode(',', array_keys($reasonDetailIds)).'}'],
            ) as $row) {
                $behaviors[$row->status_detail_id][] = (array) $row;
            }
        }

        $records = [];
        foreach ($canonical->persons as $person) {
            $id = $identity[$person->personId];
            $relationships = [];
            foreach ($person->relationships as $segment) {
                $relationships[] = $this->relationship($month, $segment, $intentions[$segment->employmentRelationshipId] ?? [], $behaviors);
            }

            $dataQuality = [];
            foreach ($relationships as $relationship) {
                array_push($dataQuality, ...$relationship['data_quality']);
            }

            $records[] = new EmploymentStatusReportPersonRecord(
                personId: $person->personId,
                nationalId: $id->national_id,
                fullNameAr: $id->full_name_ar,
                dutyClassification: $person->dutyClassification,
                relationships: $relationships,
                dataQuality: array_values(array_intersect(EmploymentStatusReportResult::DATA_QUALITY_CODES, $dataQuality)),
            );
        }

        $eventsOnly = [];
        foreach ($supplemental as $row) {
            $reason = $row->status_detail_id === null ? null : ['status_code' => $row->status_code, 'status_period_id' => $row->status_period_id, 'status_detail_id' => $row->status_detail_id];
            $eventsOnly[] = [
                'person_id' => $row->person_id, 'national_id' => $row->national_id, 'full_name_ar' => $row->full_name_ar,
                'employment_type_code' => $row->employment_type_code,
            ] + $this->terminalEvent($row->employment_relationship_id, $row->effective_to, $row->ended_terminally === null ? null : (bool) $row->ended_terminally, $reason, $behaviors)
              + ['relationship_in_monthly_population' => false];
        }

        return new EmploymentStatusReportResult($month->start, $month->next, $monthEnd, count($records), EmploymentStatusSections::build($records, $eventsOnly), $records, $eventsOnly);
    }

    /** A KNOWN end whose date E (the terminal event date) lies in [month_start, next_month_start). E == next_month_start belongs to the next month. */
    private function terminalEventInMonth(MonthInterval $month, MonthlyRelationshipSegment $segment): bool
    {
        return $segment->endKnowledgeState === 'KNOWN' && $segment->effectiveTo !== null
            && $segment->effectiveTo >= $month->start && $segment->effectiveTo < $month->next;
    }

    /**
     * One relationship of the month, all clipped to the S37 relationship window and none collapsed.
     *
     * @param  list<array<string, mixed>>  $intentionPeriods
     * @param  array<string, list<array<string, mixed>>>  $behaviors
     * @return array<string, mixed>
     */
    private function relationship(MonthInterval $month, MonthlyRelationshipSegment $segment, array $intentionPeriods, array $behaviors): array
    {
        $statusSegments = [];
        $indeterminateCoverage = $segment->endIsUncertain;
        foreach ($segment->statusSegments as $s) {
            $outcome = match (true) {
                $s['kind'] === MonthlyStatusSegmentation::UNRESOLVED => EmploymentStatusReportResult::OUTCOME_INDETERMINATE,
                $s['is_on_duty'] === true => EmploymentStatusReportResult::OUTCOME_ON_DUTY,
                default => strtoupper((string) $s['status_code']),
            };
            $indeterminateCoverage = $indeterminateCoverage || $outcome === EmploymentStatusReportResult::OUTCOME_INDETERMINATE;
            $statusSegments[] = [
                'from' => $s['from'], 'to' => $s['to'], 'outcome' => $outcome, 'kind' => $s['kind'],
                'status_code' => $s['status_code'], 'status_period_id' => $s['status_period_id'],
                'derived_from_status_period_id' => $s['derived_from_status_period_id'],
            ];
        }

        $intentionSegments = [];
        foreach (MonthlyDimensionSegmentation::segments($segment->clippedFrom, $segment->clippedTo, $intentionPeriods, true) as $s) {
            $intentionSegments[] = [
                'from' => $s['from'], 'to' => $s['to'],
                'intention' => $s['period'] === null ? 'NOT_RECORDED' : $s['period']['intention'],
                'return_intention_period_id' => $s['period']['id'] ?? null,
            ];
        }

        $terminalEvent = $this->terminalEventInMonth($month, $segment)
            ? $this->terminalEvent($segment->employmentRelationshipId, $segment->effectiveTo, $segment->endedTerminally, $segment->relationshipEndReason, $behaviors)
            : null;

        $dataQuality = [];
        if ($indeterminateCoverage) {
            $dataQuality[] = EmploymentStatusReportResult::DQ_INDETERMINATE_STATUS_COVERAGE;
        }
        if ($segment->endKnowledgeState === 'UNKNOWN_LEGACY') {
            $dataQuality[] = EmploymentStatusReportResult::DQ_UNKNOWN_LEGACY_RELATIONSHIP_END;
        }
        if ($terminalEvent !== null && $terminalEvent['reason']['state'] === EmploymentStatusReportResult::REASON_NOT_RECORDED) {
            $dataQuality[] = EmploymentStatusReportResult::DQ_RELATIONSHIP_END_REASON_NOT_RECORDED;
        }

        return [
            'employment_relationship_id' => $segment->employmentRelationshipId,
            'employment_type_code' => $segment->employmentTypeCode,
            'employee_number_scheme' => $segment->employeeNumberScheme,
            'effective_from' => $segment->effectiveFrom,
            'effective_to' => $segment->effectiveTo,
            'end_knowledge_state' => $segment->endKnowledgeState,
            'started_in_month' => $segment->effectiveFrom >= $month->start && $segment->effectiveFrom < $month->next,
            'from' => $segment->clippedFrom,
            'to' => $segment->clippedTo,
            'status_segments' => $statusSegments,
            'return_intention_segments' => $intentionSegments,
            'terminal_event' => $terminalEvent,
            'data_quality' => $dataQuality,
        ];
    }

    /**
     * One terminal event: dated effective_to (the first day no longer employed; effective_to - 1 is only the last employed day). The reason is the
     * status beginning exactly at effective_to, accepted only when its S06 behavior on that date is relationship-ending or terminal; else NOT_RECORDED.
     *
     * @param  array<string, mixed>|null  $reason  status_code / status_period_id / status_detail_id of the status beginning at effective_to
     * @param  array<string, list<array<string, mixed>>>  $behaviors
     * @return array<string, mixed>
     */
    private function terminalEvent(string $relationshipId, string $effectiveTo, ?bool $endedTerminally, ?array $reason, array $behaviors): array
    {
        if ($reason !== null && ! $this->isEndingStatus($behaviors[$reason['status_detail_id']] ?? [], $effectiveTo)) {
            $reason = null; // a status beginning at E that is neither ended nor terminal is not an end reason
        }

        return [
            'employment_relationship_id' => $relationshipId,
            'event_date' => $effectiveTo,
            'last_employed_day' => Carbon::createFromFormat('!Y-m-d', $effectiveTo)->subDay()->toDateString(),
            'reason' => $reason === null
                ? ['state' => EmploymentStatusReportResult::REASON_NOT_RECORDED, 'status_code' => null, 'status_period_id' => null]
                : ['state' => 'RECORDED', 'status_code' => $reason['status_code'], 'status_period_id' => $reason['status_period_id']],
            'ended_terminally' => $endedTerminally,
        ];
    }

    /**
     * Whether the ending status resolves, on the event date, to a relationship-ending or terminal behavior (S06 behaviors, half-open).
     *
     * @param  list<array<string, mixed>>  $periods
     */
    private function isEndingStatus(array $periods, string $eventDate): bool
    {
        foreach ($periods as $b) {
            if ($b['effective_from'] <= $eventDate && ($b['effective_to'] === null || $b['effective_to'] > $eventDate)) {
                return (bool) $b['is_relationship_ending'] || (bool) $b['is_terminal'];
            }
        }

        return false;
    }
}
