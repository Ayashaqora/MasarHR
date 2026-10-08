<?php

namespace App\Modules\HumanResources\Application\Queries\Reporting;

use App\Modules\HumanResources\Domain\MonthInterval;
use App\Modules\HumanResources\Domain\MonthlyDutyClassification;
use App\Modules\HumanResources\Domain\MonthlyStatusSegmentation;
use App\Modules\HumanResources\Domain\MonthlyWorkplaceSegmentation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The S37 monthly workforce reporting foundation
 * (docs/monthly-workforce-reporting-semantics-foundation-specification.md). INTERNAL and READ-ONLY, over the
 * existing schema: no migration, route, permission, scheduler or output. ListReportingPopulationAsOf (S27) is
 * neither extended nor changed; this is a separate, isolated query.
 *
 * Month = [month_start, next_month_start). Canonical overlap: from < next AND (to IS NULL OR to > start).
 *
 * Grain: PERSON. A Person is in the population when at least one of their Employment Relationships overlaps the
 * month — relationship effectivity is the ONLY inclusion criterion (no status, no behavior flag, and never
 * counts_in_monthly_reporting). Exactly one row per Person; every relationship keeps its own segment.
 *
 * Query strategy: a CONSTANT number of set-based statements (8), independent of population size — relationships,
 * status periods, placements, full secondments, workplace assignments, partial secondments (+weekdays), work
 * schedules and qualifications — each restricted by the same relationship-overlap predicate, then assembled in
 * memory by pure Domain segmentation classes. No per-person or per-relationship query (no N+1), and qualifications
 * are loaded separately, so they can never multiply a row.
 *
 * Status gaps are never coerced (UNRESOLVED), the workplace reuses S27/S30's single decision table, nothing is
 * counted or averaged, and no monthly value is chosen for specialty, job title, contract, category or cadre
 * (those may change inside the month; the final report rules are deferred). Qualifications are CURRENT recorded
 * Person facts (S36), not as-of-month facts. birth_date is exposed as a DATE; no age is calculated.
 * Every nested collection has a deterministic technical order.
 */
final class ListMonthlyReportingPopulation
{
    /**
     * @param  string|Carbon  $monthStart  the first day of the reporting month (Y-m-01)
     * @param  list<string>|null  $personIds  optional restriction (null = all)
     */
    public function __invoke(string|Carbon $monthStart, ?array $personIds = null): MonthlyReportingPopulation
    {
        $month = MonthInterval::of($monthStart);

        if ($personIds === []) {
            return new MonthlyReportingPopulation($month->start, $month->next, MonthlyReportingPopulation::QUALIFICATION_SEMANTICS, []);
        }

        $bindings = [$month->next, $month->start];
        $restriction = '';
        if ($personIds !== null) {
            $restriction = ' AND r.person_id IN ('.implode(',', array_fill(0, count($personIds), '?')).')';
            array_push($bindings, ...array_values($personIds));
        }
        // The one inclusion predicate, reused by every statement below.
        $overlapping = "SELECT r.id FROM hr.employment_relationships r WHERE r.effective_from < ? AND (r.effective_to IS NULL OR r.effective_to > ?){$restriction}";
        $overlappingPersons = str_replace('SELECT r.id', 'SELECT r.person_id', $overlapping);

        $relationships = DB::select(<<<SQL
            SELECT r.id, r.person_id, r.employment_type_id, et.code AS employment_type_code, r.employee_number_scheme,
                   r.effective_from, r.effective_to, r.end_knowledge_state, r.ended_terminally,
                   per.gender_id, per.birth_date
            FROM hr.employment_relationships r
            JOIN hr.persons per ON per.id = r.person_id
            JOIN ref.employment_types et ON et.id = r.employment_type_id
            WHERE r.id IN ({$overlapping})
            ORDER BY r.person_id, r.effective_from, r.id
            SQL, $bindings);

        if ($relationships === []) {
            return new MonthlyReportingPopulation($month->start, $month->next, MonthlyReportingPopulation::QUALIFICATION_SEMANTICS, []);
        }

        $windowed = fn (string $table) => DB::select(
            "SELECT x.id, x.employment_relationship_id, x.organizational_unit_id, x.effective_from, x.effective_to
             FROM {$table} x
             WHERE x.effective_from < ? AND (x.effective_to IS NULL OR x.effective_to > ?) AND x.employment_relationship_id IN ({$overlapping})
             ORDER BY x.employment_relationship_id, x.effective_from, x.id",
            [$month->next, $month->start, ...$bindings],
        );

        $statusByRelationship = $this->groupBy(DB::select(
            "SELECT sp.id, sp.employment_relationship_id, sp.status_detail_id, sd.code AS status_code, sp.effective_from, sp.effective_to
             FROM hr.employment_status_periods sp
             JOIN ref.employment_status_details sd ON sd.id = sp.status_detail_id
             WHERE sp.effective_from <= ? AND sp.employment_relationship_id IN ({$overlapping})
             ORDER BY sp.employment_relationship_id, sp.effective_from, sp.id",
            [$month->next, ...$bindings],
        ));
        $placements = $this->groupBy($windowed('hr.organizational_placement_periods'));
        $secondments = $this->groupBy($windowed('hr.full_secondment_periods'));
        $assignments = $this->groupBy($windowed('hr.workplace_assignment_periods'));

        $partials = $this->groupBy(array_map(function (object $p): object {
            $p->weekdays = $p->weekdays === null ? [] : json_decode($p->weekdays, true);

            return $p;
        }, DB::select(
            "SELECT ps.id, ps.employment_relationship_id, ps.organizational_unit_id, ps.effective_from, ps.effective_to,
                    (SELECT json_agg(w.code ORDER BY w.iso_day_number)
                     FROM hr.partial_secondment_period_weekdays pw JOIN ref.weekdays w ON w.id = pw.weekday_id
                     WHERE pw.partial_secondment_period_id = ps.id) AS weekdays
             FROM hr.partial_secondment_periods ps
             WHERE ps.effective_from < ? AND (ps.effective_to IS NULL OR ps.effective_to > ?) AND ps.employment_relationship_id IN ({$overlapping})
             ORDER BY ps.employment_relationship_id, ps.effective_from, ps.id",
            [$month->next, $month->start, ...$bindings],
        )));

        $schedules = $this->groupBy(array_map(function (object $s): object {
            $s->weekdays = $s->weekdays === null ? [] : json_decode($s->weekdays, true);

            return $s;
        }, DB::select(
            "SELECT ws.id, ws.employment_relationship_id, ws.effective_from, ws.effective_to,
                    (SELECT json_agg(w.code ORDER BY w.iso_day_number)
                     FROM hr.work_schedule_period_weekdays sw JOIN ref.weekdays w ON w.id = sw.weekday_id
                     WHERE sw.work_schedule_period_id = ws.id) AS weekdays
             FROM hr.work_schedule_periods ws
             WHERE ws.effective_from < ? AND (ws.effective_to IS NULL OR ws.effective_to > ?) AND ws.employment_relationship_id IN ({$overlapping})
             ORDER BY ws.employment_relationship_id, ws.effective_from, ws.id",
            [$month->next, $month->start, ...$bindings],
        )));

        $qualifications = [];
        foreach (DB::select(
            "SELECT pq.id, pq.person_id, pq.academic_degree_id, ad.code AS academic_degree_code,
                    ad.name_ar AS academic_degree_name_ar, ad.name_en AS academic_degree_name_en,
                    pq.qualification_type_id, qt.code AS qualification_type_code,
                    qt.name_ar AS qualification_type_name_ar, qt.name_en AS qualification_type_name_en
             -- S48 (docs/person-qualification-history-foundation-specification.md §S48.13, D13):
             -- a consumer this stage's own discovery found beyond the frozen spec's own verified
             -- table — repointed to the CURRENT version of each qualification.
             FROM hr.person_qualifications_current pq
             LEFT JOIN ref.academic_degrees ad ON ad.id = pq.academic_degree_id
             LEFT JOIN ref.qualification_types qt ON qt.id = pq.qualification_type_id
             WHERE pq.person_id IN ({$overlappingPersons})
             ORDER BY pq.person_id, pq.created_at, pq.id",
            $bindings,
        ) as $q) {
            $qualifications[$q->person_id][] = [
                'id' => $q->id,
                'academic_degree_id' => $q->academic_degree_id,
                'academic_degree_code' => $q->academic_degree_code,
                'academic_degree_name_ar' => $q->academic_degree_name_ar,
                'academic_degree_name_en' => $q->academic_degree_name_en,
                'qualification_type_id' => $q->qualification_type_id,
                'qualification_type_code' => $q->qualification_type_code,
                'qualification_type_name_ar' => $q->qualification_type_name_ar,
                'qualification_type_name_en' => $q->qualification_type_name_en,
            ];
        }

        $persons = [];
        foreach ($relationships as $r) {
            $persons[$r->person_id]['facts'] = $r;
            $persons[$r->person_id]['segments'][] = $this->segment(
                $month, $r,
                $statusByRelationship[$r->id] ?? [], $placements[$r->id] ?? [], $secondments[$r->id] ?? [],
                $assignments[$r->id] ?? [], $partials[$r->id] ?? [], $schedules[$r->id] ?? [],
            );
        }

        $rows = [];
        foreach ($persons as $personId => $person) {
            /** @var list<MonthlyRelationshipSegment> $segments */
            $segments = $person['segments'];

            $rows[] = new MonthlyReportingPersonRow(
                personId: $personId,
                genderId: $person['facts']->gender_id,
                birthDate: $person['facts']->birth_date,
                qualifications: $qualifications[$personId] ?? [],
                qualificationSemantics: MonthlyReportingPopulation::QUALIFICATION_SEMANTICS,
                dutyClassification: MonthlyDutyClassification::classify(
                    array_map(fn (MonthlyRelationshipSegment $s) => $s->statusSegments, $segments),
                    array_map(fn (MonthlyRelationshipSegment $s) => $s->endIsUncertain, $segments),
                ),
                relationships: $segments,
            );
        }

        return new MonthlyReportingPopulation($month->start, $month->next, MonthlyReportingPopulation::QUALIFICATION_SEMANTICS, $rows);
    }

    /**
     * @param  list<array<string, mixed>>  $statusPeriods
     * @param  list<array<string, mixed>>  $placements
     * @param  list<array<string, mixed>>  $secondments
     * @param  list<array<string, mixed>>  $assignments
     * @param  list<array<string, mixed>>  $partials
     * @param  list<array<string, mixed>>  $schedules
     */
    private function segment(MonthInterval $month, object $r, array $statusPeriods, array $placements, array $secondments, array $assignments, array $partials, array $schedules): MonthlyRelationshipSegment
    {
        [$from, $to] = $month->clip($r->effective_from, $r->effective_to);
        $uncertain = $r->end_knowledge_state === 'UNKNOWN_LEGACY';

        $statusSegments = MonthlyStatusSegmentation::segment($from, $to, $statusPeriods);
        $last = MonthlyStatusSegmentation::lastNonOnDuty($statusSegments);

        // The relationship's own KNOWN end closes this segment inside the month: expose the status that begins
        // exactly at that end (S10: the ending status starts at the end date). Separate from the temporary reason.
        $endReason = null;
        if ($r->end_knowledge_state === 'KNOWN' && $r->effective_to > $month->start && $r->effective_to < $month->next) {
            foreach ($statusPeriods as $period) {
                if ($period['effective_from'] === $r->effective_to) {
                    $endReason = [
                        'status_period_id' => $period['id'], 'status_detail_id' => $period['status_detail_id'],
                        'status_code' => $period['status_code'], 'effective_from' => $period['effective_from'],
                        'ended_terminally' => $r->ended_terminally === null ? null : (bool) $r->ended_terminally,
                    ];
                    break;
                }
            }
        }

        return new MonthlyRelationshipSegment(
            employmentRelationshipId: $r->id,
            employmentTypeId: $r->employment_type_id,
            employmentTypeCode: $r->employment_type_code,
            employeeNumberScheme: $r->employee_number_scheme,
            effectiveFrom: $r->effective_from,
            effectiveTo: $r->effective_to,
            endKnowledgeState: $r->end_knowledge_state,
            endIsUncertain: $uncertain,
            endedTerminally: $r->ended_terminally === null ? null : (bool) $r->ended_terminally,
            clippedFrom: $from,
            clippedTo: $to,
            statusSegments: $statusSegments,
            lastNonOnDutyReason: $last === null ? null : [
                'status_period_id' => $last['status_period_id'], 'status_detail_id' => $last['status_detail_id'],
                'status_code' => $last['status_code'], 'segment_from' => $last['from'], 'segment_to' => $last['to'],
            ],
            relationshipEndReason: $endReason,
            workplaceSegments: MonthlyWorkplaceSegmentation::workplace($from, $to, $placements, $secondments, $assignments, $partials),
            workScheduleSegments: MonthlyWorkplaceSegmentation::schedule($from, $to, $schedules),
        );
    }

    /**
     * @param  list<object>  $rows
     * @return array<string, list<array<string, mixed>>>
     */
    private function groupBy(array $rows): array
    {
        $grouped = [];
        foreach ($rows as $row) {
            $grouped[$row->employment_relationship_id][] = (array) $row;
        }

        return $grouped;
    }
}
