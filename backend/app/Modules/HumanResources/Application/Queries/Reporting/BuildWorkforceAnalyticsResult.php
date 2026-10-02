<?php

namespace App\Modules\HumanResources\Application\Queries\Reporting;

use App\Modules\HumanResources\Domain\CompletedAge;
use App\Modules\HumanResources\Domain\CumulativeServiceCalculator;
use App\Modules\HumanResources\Domain\MonthInterval;
use App\Modules\HumanResources\Domain\MonthlyDimensionSegmentation;
use App\Modules\HumanResources\Domain\MonthlyStatusSegmentation;
use App\Modules\HumanResources\Domain\OrganizationalHierarchyPaths;
use App\Modules\HumanResources\Domain\PartialSecondmentOccurrences;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * S44 Workforce Analytics Foundation (docs/workforce-analytics-foundation-specification.md). INTERNAL, READ-ONLY, an ANALYTICS composition
 * layer — not a fifth monthly report. The canonical S37 monthly population is computed ONCE (the only workforce population authority);
 * S40's additive fromPopulation enriches that precomputed population (so S37 never runs again); the existing pure Domain classes
 * (CompletedAge, CumulativeServiceCalculator, PartialSecondmentOccurrences, OrganizationalHierarchyPaths, MonthlyDimensionSegmentation)
 * supply every rule; the S44-owned set-based batches add only what S37/S40 do not carry. No report builder (R1–R4) is called and no HTTP
 * endpoint is composed.
 *
 * Query strategy: a CONSTANT number of statements, independent of population size — S37 (8), S40 enrichment (7) and the S44 batches:
 * (1) the gender catalog, (2) every relationship of the Person starting before the month's end (+ type), (3) those relationships' status
 * periods, (4) the Primary Qualifications, (5) the organizational placement periods of the represented relationships, (6) one recursive
 * CTE over every referenced unit and its ancestors, (7) the supplemental terminal events (KNOWN ends dated in the month that S37 does not
 * represent), (8) the effective-dated behavior of the ending statuses found at a terminal event. Batches that have nothing to read are
 * skipped; none runs per Person, relationship, unit, segment or period.
 */
final class BuildWorkforceAnalyticsResult
{
    public function __construct(
        private readonly ListMonthlyReportingPopulation $population,
        private readonly ListMonthlyWorkforceDimensions $dimensions,
    ) {}

    public function __invoke(string|Carbon $monthStart): WorkforceAnalyticsResult
    {
        $month = MonthInterval::of($monthStart);
        $monthEnd = Carbon::createFromFormat('!Y-m-d', $month->next)->subDay()->toDateString();

        $canonical = ($this->population)($month->start); // S37, exactly once
        $enriched = $this->dimensions->fromPopulation($canonical); // S40 enrichment of the precomputed population

        $personIds = [];
        $relationshipIds = [];
        $reasonDetailIds = [];
        foreach ($canonical->persons as $person) {
            $personIds[] = $person->personId;
            foreach ($person->relationships as $segment) {
                $relationshipIds[] = $segment->employmentRelationshipId;
                if ($this->endInMonth($month, $segment->endKnowledgeState, $segment->effectiveTo) && $segment->relationshipEndReason !== null) {
                    $reasonDetailIds[$segment->relationshipEndReason['status_detail_id']] = true;
                }
            }
        }

        $genders = [];
        $history = [];
        $relationshipTypes = [];
        $statuses = [];
        $primary = [];
        $placements = [];
        $units = [];
        if ($personIds !== []) {
            $ids = '{'.implode(',', $personIds).'}';

            foreach (DB::select('SELECT g.id, g.code, g.name_ar, g.name_en FROM ref.genders g') as $row) {
                $genders[$row->id] = $row;
            }

            foreach (DB::select(
                'SELECT r.id, r.person_id, r.effective_from, r.effective_to, r.end_knowledge_state,
                        r.employment_type_id, et.code AS type_code, et.name_ar AS type_name_ar, et.name_en AS type_name_en
                 FROM hr.employment_relationships r JOIN ref.employment_types et ON et.id = r.employment_type_id
                 WHERE r.person_id = ANY(CAST(? AS uuid[])) AND r.effective_from < ?
                 ORDER BY r.person_id, r.effective_from, r.id',
                [$ids, $month->next],
            ) as $row) {
                $history[$row->person_id][] = ['id' => $row->id, 'effective_from' => $row->effective_from, 'effective_to' => $row->effective_to, 'end_knowledge_state' => $row->end_knowledge_state];
                $relationshipTypes[$row->id] = ['code' => $row->type_code, 'name_ar' => $row->type_name_ar, 'name_en' => $row->type_name_en];
            }

            foreach (DB::select(
                'SELECT sp.employment_relationship_id, sd.code AS status_code, sp.effective_from, sp.effective_to, sp.travel_pay_status
                 FROM hr.employment_status_periods sp JOIN ref.employment_status_details sd ON sd.id = sp.status_detail_id
                 WHERE sp.employment_relationship_id = ANY(CAST(? AS uuid[])) AND sp.effective_from < ?
                 ORDER BY sp.employment_relationship_id, sp.effective_from, sp.id',
                ['{'.implode(',', array_keys($relationshipTypes)).'}', $month->next],
            ) as $row) {
                $statuses[$row->employment_relationship_id][] = ['status_code' => $row->status_code, 'effective_from' => $row->effective_from, 'effective_to' => $row->effective_to, 'travel_pay_status' => $row->travel_pay_status];
            }

            foreach (DB::select(
                'SELECT pq.person_id, pq.id, pq.academic_degree_id, ad.code AS degree_code, ad.name_ar AS degree_name_ar, ad.name_en AS degree_name_en,
                        pq.qualification_type_id, qt.code AS type_code, qt.name_ar AS type_name_ar, qt.name_en AS type_name_en
                 FROM hr.person_qualifications pq
                 LEFT JOIN ref.academic_degrees ad ON ad.id = pq.academic_degree_id
                 LEFT JOIN ref.qualification_types qt ON qt.id = pq.qualification_type_id
                 WHERE pq.is_primary AND pq.person_id = ANY(CAST(? AS uuid[]))',
                [$ids],
            ) as $row) {
                $primary[$row->person_id] = $row;
            }

            $unitIds = [];
            foreach (DB::select(
                'SELECT pp.id, pp.employment_relationship_id, pp.organizational_unit_id, pp.effective_from, pp.effective_to
                 FROM hr.organizational_placement_periods pp
                 WHERE pp.effective_from < ? AND (pp.effective_to IS NULL OR pp.effective_to > ?) AND pp.employment_relationship_id = ANY(CAST(? AS uuid[]))
                 ORDER BY pp.employment_relationship_id, pp.effective_from, pp.id',
                [$month->next, $month->start, '{'.implode(',', $relationshipIds).'}'],
            ) as $row) {
                $placements[$row->employment_relationship_id][] = (array) $row;
                $unitIds[$row->organizational_unit_id] = true;
            }
            foreach ($canonical->persons as $person) {
                foreach ($person->relationships as $segment) {
                    foreach ($segment->workplaceSegments as $workplace) {
                        foreach ([$workplace['organizational_unit_id'], $workplace['underlying_organizational_unit_id']] as $unitId) {
                            if ($unitId !== null) {
                                $unitIds[$unitId] = true;
                            }
                        }
                        foreach ($workplace['partial_allocations'] as $allocation) {
                            $unitIds[$allocation['organizational_unit_id']] = true;
                        }
                    }
                }
            }

            if ($unitIds !== []) {
                foreach (DB::select(
                    'WITH RECURSIVE tree AS (
                         SELECT u.id, u.parent_id, u.name, u.is_active FROM org.organizational_units u WHERE u.id = ANY(CAST(? AS uuid[]))
                         UNION
                         SELECT a.id, a.parent_id, a.name, a.is_active FROM org.organizational_units a JOIN tree ON a.id = tree.parent_id
                     )
                     SELECT id, parent_id, name, is_active FROM tree ORDER BY id',
                    ['{'.implode(',', array_keys($unitIds)).'}'],
                ) as $row) {
                    $units[$row->id] = ['id' => $row->id, 'parent_id' => $row->parent_id, 'name' => $row->name, 'is_active' => (bool) $row->is_active];
                }
                foreach ($units as $id => $unit) {
                    $units[$id]['path'] = OrganizationalHierarchyPaths::path($id, $units);
                }
            }
        }

        // KNOWN ends dated in [month_start, next_month_start) that the S37 overlap rule does not represent (an end dated exactly month_start).
        $supplemental = array_values(array_filter(DB::select(
            'SELECT r.id AS employment_relationship_id, r.person_id, r.effective_to, r.ended_terminally, et.code AS employment_type_code,
                    sp.id AS status_period_id, sp.status_detail_id, sd.code AS status_code
             FROM hr.employment_relationships r
             JOIN ref.employment_types et ON et.id = r.employment_type_id
             LEFT JOIN hr.employment_status_periods sp ON sp.employment_relationship_id = r.id AND sp.effective_from = r.effective_to
             LEFT JOIN ref.employment_status_details sd ON sd.id = sp.status_detail_id
             WHERE r.end_knowledge_state = \'KNOWN\' AND r.effective_to >= ? AND r.effective_to < ? AND r.id <> ALL(CAST(? AS uuid[]))
             ORDER BY r.person_id, r.effective_to, r.id',
            [$month->start, $month->next, '{'.implode(',', $relationshipIds).'}'],
        ), fn (object $row) => ! in_array($row->employment_relationship_id, $relationshipIds, true)));
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

        $dimensionsByRelationship = [];
        foreach ($enriched->persons as $dimensionRow) {
            foreach ($dimensionRow->relationships as $relationship) {
                $dimensionsByRelationship[$relationship->employmentRelationshipId] = $relationship;
            }
        }

        $records = [];
        $events = [];
        foreach ($canonical->persons as $person) {
            $relationships = [];
            $dataQuality = [];
            foreach ($person->relationships as $segment) {
                $relationship = $this->relationship($month, $segment, $dimensionsByRelationship[$segment->employmentRelationshipId], $relationshipTypes[$segment->employmentRelationshipId], $placements[$segment->employmentRelationshipId] ?? [], $behaviors);
                if ($relationship['terminal_event'] !== null) {
                    $events[] = ['person_id' => $person->personId, 'employment_type_code' => $relationship['employment_type']['code']] + $relationship['terminal_event'] + ['relationship_in_monthly_population' => true];
                }
                array_push($dataQuality, ...$relationship['data_quality']);
                $relationships[] = $relationship;
            }

            $gender = $person->genderId === null || ! isset($genders[$person->genderId])
                ? ['state' => 'NOT_RECORDED', 'code' => null, 'name_ar' => null, 'name_en' => null]
                : ['state' => 'RECORDED', 'code' => strtoupper($genders[$person->genderId]->code), 'name_ar' => $genders[$person->genderId]->name_ar, 'name_en' => $genders[$person->genderId]->name_en];
            if ($gender['state'] === 'NOT_RECORDED') {
                $dataQuality[] = AdministrativeReportResult::DQ_GENDER_NOT_RECORDED;
            }

            $age = CompletedAge::at($person->birthDate, $monthEnd);
            if ($age['state'] === CompletedAge::NOT_CALCULABLE) {
                $dataQuality[] = HumanCadreResult::DQ_BIRTH_DATE_AFTER_REPORT_DATE;
            }

            $serviceResult = CumulativeServiceCalculator::calculate($history[$person->personId] ?? [], $statuses, $month->next);
            if ($serviceResult['state'] === CumulativeServiceCalculator::INCOMPLETE) {
                $service = ['state' => 'INCOMPLETE', 'service_days' => null, 'completed_service_years' => null, 'remaining_service_days' => null, 'band' => CumulativeServiceCalculator::INCOMPLETE, 'reason' => $serviceResult['reason']];
                $dataQuality[] = EmploymentStatusReportResult::DQ_UNKNOWN_LEGACY_RELATIONSHIP_END;
            } else {
                $breakdown = CumulativeServiceCalculator::breakdown($serviceResult['service_days']);
                $service = ['state' => 'CALCULABLE', 'service_days' => $serviceResult['service_days'], 'completed_service_years' => $breakdown['years'], 'remaining_service_days' => $breakdown['days'], 'band' => $breakdown['band'], 'reason' => null];
                if ($serviceResult['travel_pay_not_recorded']) {
                    $dataQuality[] = HumanCadreResult::DQ_TRAVEL_PAY_STATUS_NOT_RECORDED;
                }
            }

            $primaryRow = $primary[$person->personId] ?? null;
            if ($primaryRow === null && count($person->qualifications) >= 2) {
                $dataQuality[] = HumanCadreResult::DQ_PRIMARY_QUALIFICATION_REQUIRED;
            }
            $qualification = $primaryRow === null
                ? ['state' => 'NOT_RECORDED', 'key' => 'NOT_RECORDED', 'academic_degree' => null, 'qualification_type' => null]
                : [
                    'state' => 'PRIMARY', 'key' => ($primaryRow->academic_degree_id ?? '-').'|'.($primaryRow->qualification_type_id ?? '-'),
                    'academic_degree' => $primaryRow->academic_degree_id === null ? null : ['id' => $primaryRow->academic_degree_id, 'code' => $primaryRow->degree_code, 'name_ar' => $primaryRow->degree_name_ar, 'name_en' => $primaryRow->degree_name_en],
                    'qualification_type' => $primaryRow->qualification_type_id === null ? null : ['id' => $primaryRow->qualification_type_id, 'code' => $primaryRow->type_code, 'name_ar' => $primaryRow->type_name_ar, 'name_en' => $primaryRow->type_name_en],
                ];

            $records[] = new WorkforceAnalyticsPersonRecord(
                personId: $person->personId,
                dutyClassification: $person->dutyClassification,
                gender: $gender,
                age: $age,
                service: $service,
                primaryQualification: $qualification,
                relationships: $relationships,
                dataQuality: array_values(array_intersect(WorkforceAnalyticsResult::DATA_QUALITY_CODES, $dataQuality)),
            );
        }

        foreach ($supplemental as $row) {
            $reason = $row->status_detail_id === null ? null : ['status_code' => $row->status_code, 'status_period_id' => $row->status_period_id, 'status_detail_id' => $row->status_detail_id];
            $events[] = ['person_id' => $row->person_id, 'employment_type_code' => $row->employment_type_code]
                + $this->terminalEvent($row->employment_relationship_id, $row->effective_to, $row->ended_terminally === null ? null : (bool) $row->ended_terminally, $reason, $behaviors)
                + ['relationship_in_monthly_population' => false];
        }
        usort($events, fn (array $a, array $b) => [$a['person_id'], $a['event_date'], $a['employment_relationship_id']] <=> [$b['person_id'], $b['event_date'], $b['employment_relationship_id']]);

        return new WorkforceAnalyticsResult(
            $month->start, $month->next, $monthEnd, count($records),
            WorkforceAnalyticsSections::build($records, $events, $units),
            $records, $events,
        );
    }

    /** A KNOWN end whose date E (the terminal event date) lies in [month_start, next_month_start). */
    private function endInMonth(MonthInterval $month, string $knowledgeState, ?string $effectiveTo): bool
    {
        return $knowledgeState === 'KNOWN' && $effectiveTo !== null && $effectiveTo >= $month->start && $effectiveTo < $month->next;
    }

    /**
     * One relationship of the month: the S37 status segments (interpreted, never rebuilt), the S40 category / contract / specialty segments,
     * the independent organizational placement segments and the actual-workplace occurrences — all clipped to the S37 window, none collapsed.
     *
     * @param  array{code: string, name_ar: string, name_en: ?string}  $type
     * @param  list<array<string, mixed>>  $placementPeriods
     * @param  array<string, list<array<string, mixed>>>  $behaviors
     * @return array<string, mixed>
     */
    private function relationship(MonthInterval $month, MonthlyRelationshipSegment $segment, MonthlyDimensionRelationship $dimension, array $type, array $placementPeriods, array $behaviors): array
    {
        $indeterminate = $segment->endIsUncertain;
        $statusSegments = [];
        foreach ($segment->statusSegments as $s) {
            $outcome = match (true) {
                $s['kind'] === MonthlyStatusSegmentation::UNRESOLVED => EmploymentStatusReportResult::OUTCOME_INDETERMINATE,
                $s['is_on_duty'] === true => EmploymentStatusReportResult::OUTCOME_ON_DUTY,
                default => strtoupper((string) $s['status_code']),
            };
            $indeterminate = $indeterminate || $outcome === EmploymentStatusReportResult::OUTCOME_INDETERMINATE;
            $statusSegments[] = ['from' => $s['from'], 'to' => $s['to'], 'outcome' => $outcome, 'kind' => $s['kind']];
        }

        $value = fn (array $s) => $s['value'] === null ? ['code' => null, 'name_ar' => null, 'name_en' => null] : ['code' => $s['value']['code'], 'name_ar' => $s['value']['name_ar'], 'name_en' => $s['value']['name_en']];
        $mapping = fn (?array $m) => $m === null ? null : ['state' => $m['state'], 'code' => $m['target']['code'] ?? null, 'name_ar' => $m['target']['name_ar'] ?? null, 'name_en' => $m['target']['name_en'] ?? null];

        $categories = array_map(fn (array $s) => ['from' => $s['from'], 'to' => $s['to'], 'state' => $s['state']] + $value($s), $dimension->categorySegments);
        $contracts = array_map(fn (array $s) => ['from' => $s['from'], 'to' => $s['to'], 'state' => $s['state']] + $value($s) + ['population_mapping' => $mapping($s['population_category'] ?? null)], $dimension->contractSegments);
        $specialties = array_map(fn (array $s) => ['from' => $s['from'], 'to' => $s['to'], 'state' => $s['state']] + $value($s) + ['cadre_mapping' => $mapping($s['cadre_category'] ?? null)], $dimension->specialtySegments);

        $placementSegments = [];
        foreach (MonthlyDimensionSegmentation::segments($segment->clippedFrom, $segment->clippedTo, $placementPeriods, true) as $s) {
            $placementSegments[] = ['from' => $s['from'], 'to' => $s['to'], 'state' => $s['state'], 'organizational_unit_id' => $s['period']['organizational_unit_id'] ?? null];
        }

        $workplaces = [];
        foreach ($segment->workplaceSegments as $w) {
            foreach ($this->workplaceOccurrences($w) as $occurrence) {
                $workplaces[] = $occurrence;
            }
        }

        $terminalEvent = $this->endInMonth($month, $segment->endKnowledgeState, $segment->effectiveTo)
            ? $this->terminalEvent($segment->employmentRelationshipId, $segment->effectiveTo, $segment->endedTerminally, $segment->relationshipEndReason, $behaviors)
            : null;

        $dataQuality = [];
        if ($indeterminate) {
            $dataQuality[] = EmploymentStatusReportResult::DQ_INDETERMINATE_STATUS_COVERAGE;
        }
        if (count(array_filter($placementSegments, fn (array $s) => $s['state'] !== 'RESOLVED')) > 0) {
            $dataQuality[] = AdministrativeReportResult::DQ_ORGANIZATIONAL_PLACEMENT_NOT_RECORDED;
        }
        if (count(array_filter($workplaces, fn (array $s) => $s['state'] !== 'RESOLVED')) > 0) {
            $dataQuality[] = AdministrativeReportResult::DQ_ACTUAL_WORKPLACE_NOT_DETERMINABLE;
        }
        if ($terminalEvent !== null && $terminalEvent['reason']['state'] === EmploymentStatusReportResult::REASON_NOT_RECORDED) {
            $dataQuality[] = EmploymentStatusReportResult::DQ_RELATIONSHIP_END_REASON_NOT_RECORDED;
        }

        return [
            'employment_relationship_id' => $segment->employmentRelationshipId,
            'employment_type' => $type,
            'effective_from' => $segment->effectiveFrom,
            'effective_to' => $segment->effectiveTo,
            'end_knowledge_state' => $segment->endKnowledgeState,
            'started_in_month' => $segment->effectiveFrom >= $month->start && $segment->effectiveFrom < $month->next,
            'from' => $segment->clippedFrom,
            'to' => $segment->clippedTo,
            'status_segments' => $statusSegments,
            'category_segments' => $categories,
            'contract_segments' => $contracts,
            'specialty_segments' => $specialties,
            'placement_segments' => $placementSegments,
            'actual_workplace_segments' => $workplaces,
            'terminal_event' => $terminalEvent,
            'data_quality' => $dataQuality,
        ];
    }

    /**
     * The actual-workplace occurrences of one S37 workplace segment (the R2 semantics, read from the S37 segment and the Domain class):
     * a RESOLVED segment is one occurrence of its movement type; a PARTIAL_ALLOCATION segment yields one occurrence per destination with
     * at least one applicable scheduled weekday (a destination with none yields nothing) plus the underlying PLACEMENT, flagged and without
     * weekdays; an UNRESOLVED or AMBIGUOUS segment stays non-determinable. Weekdays are an ALLOCATION — never attendance, days, FTE or a share.
     *
     * @param  array<string, mixed>  $w
     * @return list<array<string, mixed>>
     */
    private function workplaceOccurrences(array $w): array
    {
        $base = ['from' => $w['from'], 'to' => $w['to']];
        $movement = fn (?string $source) => match ($source) {
            'placement' => 'PLACEMENT',
            'secondment' => 'FULL_SECONDMENT',
            'assignment' => 'WORKPLACE_ASSIGNMENT',
            'partial_secondment' => 'PARTIAL_SECONDMENT',
            default => 'UNKNOWN',
        };

        if ($w['state'] === 'RESOLVED') {
            return [$base + ['state' => 'RESOLVED', 'organizational_unit_id' => $w['organizational_unit_id'], 'movement_type' => $movement($w['source']), 'weekdays' => null]];
        }

        if ($w['state'] === 'PARTIAL_ALLOCATION') {
            $occurrences = [];
            foreach ($w['partial_allocations'] as $allocation) {
                $applicable = PartialSecondmentOccurrences::applicableWeekdays($w['from'], $w['to'], $allocation['effective_from'], $allocation['effective_to'], $allocation['weekdays']);
                if ($applicable === []) {
                    continue;
                }
                $window = MonthInterval::intersect($allocation['effective_from'], $allocation['effective_to'], $w['from'], $w['to']);
                $occurrences[] = ['from' => $window[0], 'to' => $window[1], 'state' => 'RESOLVED', 'organizational_unit_id' => $allocation['organizational_unit_id'], 'movement_type' => 'PARTIAL_SECONDMENT', 'weekdays' => $applicable];
            }
            if ($w['underlying_organizational_unit_id'] !== null) {
                $occurrences[] = $base + ['state' => 'RESOLVED', 'organizational_unit_id' => $w['underlying_organizational_unit_id'], 'movement_type' => 'PLACEMENT', 'weekdays' => null, 'underlying_of_partial_allocation' => true];
            }

            return $occurrences;
        }

        return [$base + ['state' => $w['state'], 'organizational_unit_id' => null, 'movement_type' => null, 'weekdays' => null]];
    }

    /**
     * One terminal event: dated effective_to (the first day no longer employed; effective_to - 1 is only the last employed day). The reason is
     * the status beginning exactly at effective_to, accepted only when its S06 behavior on that date is relationship-ending or terminal;
     * otherwise NOT_RECORDED. ended_terminally is a separate fact and never a reason.
     *
     * @param  array<string, mixed>|null  $reason
     * @param  array<string, list<array<string, mixed>>>  $behaviors
     * @return array<string, mixed>
     */
    private function terminalEvent(string $relationshipId, string $effectiveTo, ?bool $endedTerminally, ?array $reason, array $behaviors): array
    {
        if ($reason !== null && ! $this->isEndingStatus($behaviors[$reason['status_detail_id']] ?? [], $effectiveTo)) {
            $reason = null;
        }

        return [
            'employment_relationship_id' => $relationshipId,
            'event_date' => $effectiveTo,
            'last_employed_day' => Carbon::createFromFormat('!Y-m-d', $effectiveTo)->subDay()->toDateString(),
            'reason' => $reason === null
                ? ['state' => EmploymentStatusReportResult::REASON_NOT_RECORDED, 'status_code' => null]
                : ['state' => 'RECORDED', 'status_code' => $reason['status_code']],
            'ended_terminally' => $endedTerminally,
        ];
    }

    /** @param  list<array<string, mixed>>  $periods */
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
