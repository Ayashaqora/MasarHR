<?php

namespace App\Modules\HumanResources\Application\Queries\Reporting;

use App\Modules\HumanResources\Domain\CompletedAge;
use App\Modules\HumanResources\Domain\CumulativeServiceCalculator;
use App\Modules\HumanResources\Domain\MonthInterval;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * REPORT-1 Monthly Human Cadre (docs/human-cadre-report-foundation-specification.md). INTERNAL, READ-ONLY. The canonical S37 monthly
 * population is computed ONCE and enriched by S40's additive fromPopulation; this class neither re-derives the population nor
 * copies any S40 algorithm. It selects, per Person, the latest qualifying relationship, classifies at the classification date,
 * and builds ONE canonical record per Person, from which the official summaries are computed.
 *
 * Query strategy: a CONSTANT 20 statements, independent of population size — S37 (8), S40 enrichment (7), and five R1 batches:
 * Person identity + gender label, every documented relationship starting before the boundary (+ type label), those relationships'
 * status periods, the Primary Qualifications (+ labels), and the cadre category catalog (to list the zero-count official buckets).
 * No per-Person, per-relationship or per-segment query.
 */
final class BuildHumanCadreResult
{
    public function __construct(
        private readonly ListMonthlyReportingPopulation $population,
        private readonly ListMonthlyWorkforceDimensions $dimensions,
    ) {}

    public function __invoke(string|Carbon $monthStart): HumanCadreResult
    {
        $month = MonthInterval::of($monthStart);
        $monthEnd = Carbon::createFromFormat('!Y-m-d', $month->next)->subDay()->toDateString();

        $canonical = ($this->population)($month->start);
        $enriched = $this->dimensions->fromPopulation($canonical);

        $cadreCatalog = DB::select('SELECT id, code, name_ar, name_en, is_active FROM ref.monthly_cadre_categories ORDER BY display_order, code');

        $personIds = array_map(fn (MonthlyReportingPersonRow $row) => $row->personId, $canonical->persons);
        if ($personIds === []) {
            return new HumanCadreResult($month->start, $month->next, $monthEnd, 0, $this->summaries([], $cadreCatalog), []);
        }
        $ids = '{'.implode(',', $personIds).'}';

        $identity = [];
        foreach (DB::select(
            'SELECT p.id, p.national_id, p.full_name_ar, p.gender_id, g.code AS gender_code, g.name_ar AS gender_name_ar, g.name_en AS gender_name_en
             FROM hr.persons p LEFT JOIN ref.genders g ON g.id = p.gender_id
             WHERE p.id = ANY(CAST(? AS uuid[]))',
            [$ids],
        ) as $row) {
            $identity[$row->id] = $row;
        }

        $history = [];
        $relationshipTypes = [];
        foreach (DB::select(
            'SELECT r.id, r.person_id, r.effective_from, r.effective_to, r.end_knowledge_state,
                    r.employment_type_id, et.code AS type_code, et.name_ar AS type_name_ar, et.name_en AS type_name_en
             FROM hr.employment_relationships r JOIN ref.employment_types et ON et.id = r.employment_type_id
             WHERE r.person_id = ANY(CAST(? AS uuid[])) AND r.effective_from < ?
             ORDER BY r.person_id, r.effective_from, r.id',
            [$ids, $month->next],
        ) as $row) {
            $history[$row->person_id][] = ['id' => $row->id, 'effective_from' => $row->effective_from, 'effective_to' => $row->effective_to, 'end_knowledge_state' => $row->end_knowledge_state];
            $relationshipTypes[$row->id] = ['id' => $row->employment_type_id, 'code' => $row->type_code, 'name_ar' => $row->type_name_ar, 'name_en' => $row->type_name_en];
        }

        $statuses = [];
        foreach (DB::select(
            'SELECT sp.employment_relationship_id, sd.code AS status_code, sp.effective_from, sp.effective_to, sp.travel_pay_status
             FROM hr.employment_status_periods sp JOIN ref.employment_status_details sd ON sd.id = sp.status_detail_id
             WHERE sp.employment_relationship_id = ANY(CAST(? AS uuid[])) AND sp.effective_from < ?
             ORDER BY sp.employment_relationship_id, sp.effective_from, sp.id',
            ['{'.implode(',', array_keys($relationshipTypes)).'}', $month->next],
        ) as $row) {
            $statuses[$row->employment_relationship_id][] = ['status_code' => $row->status_code, 'effective_from' => $row->effective_from, 'effective_to' => $row->effective_to, 'travel_pay_status' => $row->travel_pay_status];
        }

        $primary = [];
        foreach (DB::select(
            // S48 (docs/person-qualification-history-foundation-specification.md §S48.13, D13):
            // repointed to the CURRENT version of each qualification; the join target is the only
            // change — HumanCadrePersonRecord's shape and DQ_PRIMARY_QUALIFICATION_REQUIRED are
            // unchanged.
            'SELECT pq.person_id, pq.id, pq.academic_degree_id, ad.code AS degree_code, ad.name_ar AS degree_name_ar, ad.name_en AS degree_name_en,
                    pq.qualification_type_id, qt.code AS type_code, qt.name_ar AS type_name_ar, qt.name_en AS type_name_en
             FROM hr.person_qualifications_current pq
             LEFT JOIN ref.academic_degrees ad ON ad.id = pq.academic_degree_id
             LEFT JOIN ref.qualification_types qt ON qt.id = pq.qualification_type_id
             WHERE pq.is_primary AND pq.person_id = ANY(CAST(? AS uuid[]))',
            [$ids],
        ) as $row) {
            $primary[$row->person_id] = $row;
        }

        $dimensionsByRelationship = [];
        foreach ($enriched->persons as $dimensionRow) {
            foreach ($dimensionRow->relationships as $relationship) {
                $dimensionsByRelationship[$relationship->employmentRelationshipId] = $relationship;
            }
        }

        $rows = [];
        foreach ($canonical->persons as $person) {
            $selected = $person->relationships[array_key_last($person->relationships)];
            $classificationDate = ($selected->effectiveTo === null || $selected->effectiveTo >= $month->next)
                ? $monthEnd
                : Carbon::createFromFormat('!Y-m-d', $selected->effectiveTo)->subDay()->toDateString();

            [$specialty, $cadre] = $this->classify($dimensionsByRelationship[$selected->employmentRelationshipId], $classificationDate);

            $id = $identity[$person->personId];
            $dataQuality = [];

            $age = CompletedAge::at($person->birthDate, $monthEnd);
            if ($age['state'] === CompletedAge::NOT_CALCULABLE) {
                $dataQuality[] = HumanCadreResult::DQ_BIRTH_DATE_AFTER_REPORT_DATE;
            }

            $primaryRow = $primary[$person->personId] ?? null;
            if ($primaryRow === null && count($person->qualifications) >= 2) {
                $dataQuality[] = HumanCadreResult::DQ_PRIMARY_QUALIFICATION_REQUIRED;
            }

            $serviceResult = CumulativeServiceCalculator::calculate($history[$person->personId] ?? [], $statuses, $month->next);
            if ($serviceResult['state'] === CumulativeServiceCalculator::INCOMPLETE) {
                $service = ['state' => 'INCOMPLETE', 'service_days' => null, 'completed_service_years' => null, 'remaining_service_days' => null, 'display' => null, 'band' => CumulativeServiceCalculator::INCOMPLETE, 'reason' => $serviceResult['reason']];
                $dataQuality[] = HumanCadreResult::DQ_UNKNOWN_LEGACY_RELATIONSHIP_END;
            } else {
                $breakdown = CumulativeServiceCalculator::breakdown($serviceResult['service_days']);
                $service = [
                    'state' => 'CALCULABLE', 'service_days' => $serviceResult['service_days'], 'completed_service_years' => $breakdown['years'],
                    'remaining_service_days' => $breakdown['days'], 'display' => "{$breakdown['years']} years + {$breakdown['days']} days", 'band' => $breakdown['band'], 'reason' => null,
                ];
                if ($serviceResult['travel_pay_not_recorded']) {
                    $dataQuality[] = HumanCadreResult::DQ_TRAVEL_PAY_STATUS_NOT_RECORDED;
                }
            }

            $rows[] = new HumanCadrePersonRecord(
                personId: $person->personId,
                nationalId: $id->national_id,
                fullNameAr: $id->full_name_ar,
                gender: $id->gender_id === null
                    ? ['state' => 'NOT_RECORDED', 'id' => null, 'code' => null, 'name_ar' => null, 'name_en' => null]
                    : ['state' => 'RECORDED', 'id' => $id->gender_id, 'code' => $id->gender_code, 'name_ar' => $id->gender_name_ar, 'name_en' => $id->gender_name_en],
                selectedRelationship: [
                    'employment_relationship_id' => $selected->employmentRelationshipId,
                    'effective_from' => $selected->effectiveFrom,
                    'effective_to' => $selected->effectiveTo,
                    'end_knowledge_state' => $selected->endKnowledgeState,
                    'employment_type' => $relationshipTypes[$selected->employmentRelationshipId],
                ],
                classificationDate: $classificationDate,
                specialty: $specialty,
                cadre: $cadre,
                qualification: $primaryRow === null
                    ? ['state' => 'NOT_RECORDED', 'qualification_id' => null, 'academic_degree' => null, 'qualification_type' => null]
                    : [
                        'state' => 'PRIMARY', 'qualification_id' => $primaryRow->id,
                        'academic_degree' => $primaryRow->academic_degree_id === null ? null : ['id' => $primaryRow->academic_degree_id, 'code' => $primaryRow->degree_code, 'name_ar' => $primaryRow->degree_name_ar, 'name_en' => $primaryRow->degree_name_en],
                        'qualification_type' => $primaryRow->qualification_type_id === null ? null : ['id' => $primaryRow->qualification_type_id, 'code' => $primaryRow->type_code, 'name_ar' => $primaryRow->type_name_ar, 'name_en' => $primaryRow->type_name_en],
                    ],
                age: $age,
                service: $service,
                dataQuality: $dataQuality,
            );
        }

        return new HumanCadreResult($month->start, $month->next, $monthEnd, count($rows), $this->summaries($rows, $cadreCatalog), $rows);
    }

    /**
     * The selected specialty and the cadre classification at the classification date, read from the S40 segments.
     *
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function classify(MonthlyDimensionRelationship $relationship, string $date): array
    {
        $segment = null;
        foreach ($relationship->specialtySegments as $candidate) {
            if ($candidate['from'] <= $date && $date < $candidate['to']) {
                $segment = $candidate;
                break;
            }
        }

        if ($segment === null || $segment['state'] !== 'RESOLVED') {
            return [
                ['state' => 'NOT_RECORDED', 'id' => null, 'code' => null, 'name_ar' => null, 'name_en' => null],
                ['state' => HumanCadreResult::UNCLASSIFIED, 'unclassified_reason' => 'NOT_RECORDED', 'id' => null, 'code' => null, 'name_ar' => null, 'name_en' => null],
            ];
        }

        $specialty = ['state' => 'RESOLVED', 'id' => $segment['value']['id'], 'code' => $segment['value']['code'], 'name_ar' => $segment['value']['name_ar'], 'name_en' => $segment['value']['name_en']];
        $mapping = $segment['cadre_category'];

        if ($mapping === null || $mapping['state'] !== 'RESOLVED') {
            return [$specialty, ['state' => HumanCadreResult::UNCLASSIFIED, 'unclassified_reason' => 'UNMAPPED', 'id' => null, 'code' => null, 'name_ar' => null, 'name_en' => null]];
        }

        return [$specialty, [
            'state' => 'RESOLVED', 'unclassified_reason' => null,
            'id' => $mapping['target']['id'], 'code' => $mapping['target']['code'], 'name_ar' => $mapping['target']['name_ar'], 'name_en' => $mapping['target']['name_en'],
        ]];
    }

    /**
     * The official summaries, all computed from the canonical records (never from a second population).
     *
     * @param  list<HumanCadrePersonRecord>  $rows
     * @param  list<object>  $cadreCatalog
     * @return array<string, mixed>
     */
    private function summaries(array $rows, array $cadreCatalog): array
    {
        $cadre = [];
        foreach ($cadreCatalog as $category) {
            $cadre[$category->code] = ['code' => $category->code, 'name_ar' => $category->name_ar, 'name_en' => $category->name_en, 'is_active' => (bool) $category->is_active, 'count' => 0];
        }
        $unclassified = ['count' => 0, 'reasons' => ['NOT_RECORDED' => 0, 'UNMAPPED' => 0]];
        $specialties = [];
        $specialtyNotRecorded = 0;
        $types = [];
        $genders = [];
        $genderNotRecorded = 0;
        $qualifications = [];
        $qualificationNotRecorded = 0;
        $ages = array_fill_keys(CompletedAge::BANDS, 0);
        $services = array_fill_keys(CumulativeServiceCalculator::BANDS, 0);

        foreach ($rows as $row) {
            if ($row->cadre['state'] === 'RESOLVED') {
                $code = $row->cadre['code'];
                $cadre[$code] ??= ['code' => $code, 'name_ar' => $row->cadre['name_ar'], 'name_en' => $row->cadre['name_en'], 'is_active' => true, 'count' => 0];
                $cadre[$code]['count']++;
            } else {
                $unclassified['count']++;
                $unclassified['reasons'][$row->cadre['unclassified_reason']]++;
            }

            if ($row->specialty['state'] === 'RESOLVED') {
                $specialties[$row->specialty['id']] ??= ['specialty_id' => $row->specialty['id'], 'code' => $row->specialty['code'], 'name_ar' => $row->specialty['name_ar'], 'name_en' => $row->specialty['name_en'], 'count' => 0];
                $specialties[$row->specialty['id']]['count']++;
            } else {
                $specialtyNotRecorded++;
            }

            $type = $row->selectedRelationship['employment_type'];
            $types[$type['code']] ??= ['code' => $type['code'], 'name_ar' => $type['name_ar'], 'name_en' => $type['name_en'], 'count' => 0];
            $types[$type['code']]['count']++;

            if ($row->gender['state'] === 'RECORDED') {
                $genders[$row->gender['code']] ??= ['code' => $row->gender['code'], 'name_ar' => $row->gender['name_ar'], 'name_en' => $row->gender['name_en'], 'count' => 0];
                $genders[$row->gender['code']]['count']++;
            } else {
                $genderNotRecorded++;
            }

            if ($row->qualification['state'] === 'PRIMARY') {
                $degree = $row->qualification['academic_degree'];
                $qualificationType = $row->qualification['qualification_type'];
                $key = ($degree['id'] ?? '-').'|'.($qualificationType['id'] ?? '-');
                $qualifications[$key] ??= ['academic_degree' => $degree, 'qualification_type' => $qualificationType, 'count' => 0];
                $qualifications[$key]['count']++;
            } else {
                $qualificationNotRecorded++;
            }

            $ages[$row->age['band']]++;
            $services[$row->service['band']]++;
        }

        ksort($specialties);
        uasort($specialties, fn (array $a, array $b) => [$a['code'], $a['specialty_id']] <=> [$b['code'], $b['specialty_id']]);
        ksort($types);
        ksort($genders);
        ksort($qualifications);

        $bands = fn (array $counts) => array_map(fn (string $band, int $count) => ['band' => $band, 'count' => $count], array_keys($counts), array_values($counts));

        return [
            'cadre' => ['categories' => array_values($cadre), 'unclassified' => $unclassified],
            'specialty' => ['specialties' => array_values($specialties), 'not_recorded' => $specialtyNotRecorded],
            'employment_type' => array_values($types),
            'gender' => ['genders' => array_values($genders), 'not_recorded' => $genderNotRecorded],
            'qualification' => ['primary_qualifications' => array_values($qualifications), 'not_recorded' => $qualificationNotRecorded],
            'age' => $bands($ages),
            'service' => $bands($services),
        ];
    }
}
