<?php

namespace App\Modules\HumanResources\Application\Queries\Reporting;

use App\Modules\HumanResources\Domain\MonthlyDimensionSegmentation as Seg;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The S40 monthly workforce multi-value dimensions foundation
 * (docs/monthly-workforce-multi-value-dimensions-specification.md). INTERNAL and READ-ONLY, over the existing
 * schema: no migration, route, permission or output. It is NOT a report and NOT a second population engine: the
 * canonical S37 population (ListMonthlyReportingPopulation) is called exactly once and its Persons and relationship
 * windows are enriched; S37 itself is untouched.
 *
 * Per relationship, four temporal histories (employment category, contract, job title, specialty) are cut to the
 * relationship's month window [clipped_from, clipped_to) as half-open segments: RESOLVED (a recorded period),
 * NOT_RECORDED (a gap, never filled) or NOT_APPLICABLE (a contract on a non-CONTRACT relationship). The three
 * reference mappings (specialty→cadre category, job title→administrator classification, contract type→population
 * category) are resolved AS-OF each sub-interval (RESOLVED | UNMAPPED, never "other"). No monthly scalar, no total.
 *
 * Query strategy: a CONSTANT number of set-based statements (S37's 8, the four streams, the three mappings = 15),
 * independent of population size; catalog codes and names are JOINed one-to-one (current-recorded labels). No
 * per-Person, per-relationship or per-segment query, and no flat join across streams.
 */
final class ListMonthlyWorkforceDimensions
{
    public function __construct(private readonly ListMonthlyReportingPopulation $population) {}

    /** @param  string|Carbon  $monthStart  the first day of the reporting month (Y-m-01) */
    public function __invoke(string|Carbon $monthStart, ?array $personIds = null): MonthlyWorkforceDimensions
    {
        return $this->fromPopulation(($this->population)($monthStart, $personIds));
    }

    /**
     * S41 (R1-D39): the same enrichment for an ALREADY computed canonical S37 population, so a consumer that needs the S37
     * facts as well (REPORT-1) executes S37 exactly once. This is the whole post-S37 half of __invoke, unchanged: only the
     * S40 statements run here (the four period streams and the three mappings).
     */
    public function fromPopulation(MonthlyReportingPopulation $canonical): MonthlyWorkforceDimensions
    {
        $relationshipIds = [];
        foreach ($canonical->persons as $person) {
            foreach ($person->relationships as $segment) {
                $relationshipIds[] = $segment->employmentRelationshipId;
            }
        }
        if ($relationshipIds === []) {
            return new MonthlyWorkforceDimensions($canonical->monthStart, $canonical->nextMonthStart, MonthlyWorkforceDimensions::LABEL_SEMANTICS, []);
        }

        $ids = self::uuidArray($relationshipIds);
        $window = [$canonical->nextMonthStart, $canonical->monthStart];

        $categories = $this->byRelationship(DB::select(
            'SELECT p.id, p.employment_relationship_id, p.effective_from, p.effective_to, p.employment_category_id AS value_id,
                    c.code AS value_code, c.name_ar AS value_name_ar, c.name_en AS value_name_en
             FROM hr.employment_category_periods p JOIN ref.employment_categories c ON c.id = p.employment_category_id
             WHERE '.self::overlapping('p').' AND p.employment_relationship_id = ANY(CAST(? AS uuid[]))
             ORDER BY p.employment_relationship_id, p.effective_from, p.id',
            [...$window, $ids],
        ));
        $contracts = $this->byRelationship(DB::select(
            'SELECT p.id, p.employment_relationship_id, p.effective_from, p.effective_to, p.contract_type_id AS value_id,
                    p.contractual_effective_to, p.contract_end_knowledge_state,
                    c.code AS value_code, c.name_ar AS value_name_ar, c.name_en AS value_name_en
             FROM hr.employment_contract_periods p JOIN ref.contract_types c ON c.id = p.contract_type_id
             WHERE '.self::overlapping('p').' AND p.employment_relationship_id = ANY(CAST(? AS uuid[]))
             ORDER BY p.employment_relationship_id, p.effective_from, p.id',
            [...$window, $ids],
        ));
        $jobTitles = $this->byRelationship(DB::select(
            'SELECT p.id, p.employment_relationship_id, p.effective_from, p.effective_to, p.job_title_id AS value_id, p.start_knowledge_state,
                    c.code AS value_code, c.name_ar AS value_name_ar, c.name_en AS value_name_en
             FROM hr.employment_job_title_periods p JOIN ref.job_titles c ON c.id = p.job_title_id
             WHERE '.self::overlapping('p').' AND p.employment_relationship_id = ANY(CAST(? AS uuid[]))
             ORDER BY p.employment_relationship_id, p.effective_from, p.id',
            [...$window, $ids],
        ));
        $specialties = $this->byRelationship(DB::select(
            'SELECT p.id, p.employment_relationship_id, p.effective_from, p.effective_to, p.specialty_id AS value_id,
                    c.code AS value_code, c.name_ar AS value_name_ar, c.name_en AS value_name_en
             FROM hr.employment_specialty_periods p JOIN ref.specialties c ON c.id = p.specialty_id
             WHERE '.self::overlapping('p').' AND p.employment_relationship_id = ANY(CAST(? AS uuid[]))
             ORDER BY p.employment_relationship_id, p.effective_from, p.id',
            [...$window, $ids],
        ));

        // Mappings are loaded for exactly the values in play and resolved AS-OF each sub-interval (never "today's" mapping).
        $cadre = $this->byValue(DB::select(
            'SELECT m.id, m.specialty_id AS value_id, m.effective_from, m.effective_to, m.cadre_category_id AS target_id,
                    t.code AS target_code, t.name_ar AS target_name_ar, t.name_en AS target_name_en
             FROM ref.specialty_cadre_category_mappings m JOIN ref.monthly_cadre_categories t ON t.id = m.cadre_category_id
             WHERE '.self::overlapping('m').' AND m.specialty_id = ANY(CAST(? AS uuid[]))
             ORDER BY m.specialty_id, m.effective_from, m.id',
            [...$window, self::uuidArray(self::valueIds($specialties))],
        ));
        $administrator = $this->byValue(DB::select(
            'SELECT m.id, m.job_title_id AS value_id, m.effective_from, m.effective_to, m.is_administrator
             FROM ref.job_title_administrator_classifications m
             WHERE '.self::overlapping('m').' AND m.job_title_id = ANY(CAST(? AS uuid[]))
             ORDER BY m.job_title_id, m.effective_from, m.id',
            [...$window, self::uuidArray(self::valueIds($jobTitles))],
        ));
        $population = $this->byValue(DB::select(
            'SELECT m.id, m.contract_type_id AS value_id, m.effective_from, m.effective_to, m.population_category_id AS target_id,
                    t.code AS target_code, t.name_ar AS target_name_ar, t.name_en AS target_name_en
             FROM ref.contract_type_population_mappings m JOIN ref.contract_based_population_categories t ON t.id = m.population_category_id
             WHERE '.self::overlapping('m').' AND m.contract_type_id = ANY(CAST(? AS uuid[]))
             ORDER BY m.contract_type_id, m.effective_from, m.id',
            [...$window, self::uuidArray(self::valueIds($contracts))],
        ));

        $rows = [];
        foreach ($canonical->persons as $person) {
            $relationships = [];
            foreach ($person->relationships as $s) {
                $id = $s->employmentRelationshipId;
                $from = $s->clippedFrom;
                $to = $s->clippedTo;

                $relationships[] = new MonthlyDimensionRelationship(
                    employmentRelationshipId: $id,
                    employeeNumberScheme: $s->employeeNumberScheme,
                    clippedFrom: $from,
                    clippedTo: $to,
                    categorySegments: $this->format(Seg::segments($from, $to, $categories[$id] ?? [], true), []),
                    contractSegments: $this->format(
                        Seg::mapped(Seg::segments($from, $to, $contracts[$id] ?? [], $s->employeeNumberScheme === 'CONTRACT'), $population, 'value_id'),
                        ['contractual_effective_to', 'contract_end_knowledge_state'], 'population_category',
                    ),
                    jobTitleSegments: $this->format(
                        Seg::mapped(Seg::segments($from, $to, $jobTitles[$id] ?? [], true), $administrator, 'value_id'),
                        ['start_knowledge_state'], 'administrator_classification',
                    ),
                    specialtySegments: $this->format(
                        Seg::mapped(Seg::segments($from, $to, $specialties[$id] ?? [], true), $cadre, 'value_id'),
                        [], 'cadre_category',
                    ),
                );
            }
            $rows[] = new MonthlyDimensionPersonRow($person->personId, $relationships);
        }

        return new MonthlyWorkforceDimensions($canonical->monthStart, $canonical->nextMonthStart, MonthlyWorkforceDimensions::LABEL_SEMANTICS, $rows);
    }

    /**
     * @param  list<array<string, mixed>>  $segments  output of MonthlyDimensionSegmentation::segments / ::mapped
     * @param  list<string>  $extras  period columns copied verbatim (the contract term, the job-title start knowledge)
     * @return list<array<string, mixed>>
     */
    private function format(array $segments, array $extras, ?string $mappingKey = null): array
    {
        $out = [];
        foreach ($segments as $s) {
            $p = $s['period'];
            $segment = [
                'from' => $s['from'],
                'to' => $s['to'],
                'state' => $s['state'],
                'period_id' => $p['id'] ?? null,
                'effective_from' => $p['effective_from'] ?? null,
                'effective_to' => $p['effective_to'] ?? null,
                'value' => $p === null ? null : [
                    'id' => $p['value_id'], 'code' => $p['value_code'], 'name_ar' => $p['value_name_ar'], 'name_en' => $p['value_name_en'],
                    'label_semantics' => MonthlyWorkforceDimensions::LABEL_SEMANTICS,
                ],
            ];
            foreach ($extras as $extra) {
                $segment[$extra] = $p[$extra] ?? null;
            }
            if ($mappingKey !== null) {
                $segment[$mappingKey] = $s['mapping_state'] === null ? null : $this->mapping($s['mapping_state'], $s['mapping']);
            }
            $out[] = $segment;
        }

        return $out;
    }

    /** @return array<string, mixed> */
    private function mapping(string $state, ?array $m): array
    {
        $block = ['state' => $state, 'mapping_id' => $m['id'] ?? null, 'effective_from' => $m['effective_from'] ?? null, 'effective_to' => $m['effective_to'] ?? null];
        if ($m !== null && array_key_exists('is_administrator', $m)) {
            $block['is_administrator'] = (bool) $m['is_administrator'];
        } elseif ($m !== null) {
            $block['target'] = [
                'id' => $m['target_id'], 'code' => $m['target_code'], 'name_ar' => $m['target_name_ar'], 'name_en' => $m['target_name_en'],
                'label_semantics' => MonthlyWorkforceDimensions::LABEL_SEMANTICS,
            ];
        }

        return $block;
    }

    private static function overlapping(string $alias): string
    {
        return "{$alias}.effective_from < ? AND ({$alias}.effective_to IS NULL OR {$alias}.effective_to > ?)";
    }

    /** @param  list<string>  $ids */
    private static function uuidArray(array $ids): string
    {
        return '{'.implode(',', array_unique($ids)).'}';
    }

    /**
     * @param  array<string, list<array<string, mixed>>>  $byRelationship
     * @return list<string>
     */
    private static function valueIds(array $byRelationship): array
    {
        $ids = [];
        foreach ($byRelationship as $periods) {
            foreach ($periods as $period) {
                $ids[] = $period['value_id'];
            }
        }

        return $ids;
    }

    /**
     * @param  list<object>  $rows
     * @return array<string, list<array<string, mixed>>>
     */
    private function byRelationship(array $rows): array
    {
        $grouped = [];
        foreach ($rows as $row) {
            $grouped[$row->employment_relationship_id][] = (array) $row;
        }

        return $grouped;
    }

    /**
     * @param  list<object>  $rows
     * @return array<string, list<array<string, mixed>>>
     */
    private function byValue(array $rows): array
    {
        $grouped = [];
        foreach ($rows as $row) {
            $grouped[$row->value_id][] = (array) $row;
        }

        return $grouped;
    }
}
