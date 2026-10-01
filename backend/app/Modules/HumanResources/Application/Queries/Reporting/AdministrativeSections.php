<?php

namespace App\Modules\HumanResources\Application\Queries\Reporting;

/**
 * The official R2 sections, computed from the canonical Person records ONLY (docs/administrative-report-foundation-specification.md
 * §S42.10) — no section recalculates the population or a business rule on its own. Pure: no database, no clock.
 *
 * Counting rules (§S42.9): the overall headcount is the number of distinct Person records; every bucket counts DISTINCT person_id
 * within itself; the temporal / multi-value dimensions (job title, administrator classification, organizational placement, actual
 * workplace) may place one Person in several buckets, so their bucket sums are NOT required to equal the overall headcount. Gender
 * is single-valued (CURRENT_RECORDED) and reconciles: MALE + FEMALE + NOT_RECORDED = overall. No percentage is ever produced.
 */
final class AdministrativeSections
{
    /** @var list<string> */
    public const ADMINISTRATOR_CLASSIFICATIONS = ['ADMINISTRATOR', 'NOT_ADMINISTRATOR', 'UNMAPPED', 'NOT_RECORDED'];

    /**
     * The data-quality codes affecting one Person (an Indeterminate Person is never a record: it is reported separately).
     *
     * @param  array{state: string}  $gender
     * @param  list<array<string, mixed>>  $relationships
     * @return list<string>
     */
    public static function personDataQuality(array $gender, array $relationships): array
    {
        $codes = [];
        $has = fn (callable $predicate) => count(array_filter($relationships, fn (array $r) => $predicate($r))) > 0;

        if ($has(fn (array $r) => count(array_filter($r['job_title_segments'], fn (array $s) => $s['state'] !== 'RESOLVED')) > 0)) {
            $codes[] = AdministrativeReportResult::DQ_JOB_TITLE_NOT_RECORDED;
        }
        if ($has(fn (array $r) => count(array_filter($r['job_title_segments'], fn (array $s) => $s['state'] === 'RESOLVED' && $s['administrator_classification'] === 'UNMAPPED')) > 0)) {
            $codes[] = AdministrativeReportResult::DQ_ADMINISTRATOR_MAPPING_UNMAPPED;
        }
        if ($gender['state'] === 'NOT_RECORDED') {
            $codes[] = AdministrativeReportResult::DQ_GENDER_NOT_RECORDED;
        }
        if ($has(fn (array $r) => count(array_filter($r['organizational_placement_segments'], fn (array $s) => $s['state'] !== 'RESOLVED')) > 0)) {
            $codes[] = AdministrativeReportResult::DQ_ORGANIZATIONAL_PLACEMENT_NOT_RECORDED;
        }
        if ($has(fn (array $r) => count(array_filter($r['actual_workplace_segments'], fn (array $s) => $s['state'] !== 'RESOLVED')) > 0)) {
            $codes[] = AdministrativeReportResult::DQ_ACTUAL_WORKPLACE_NOT_DETERMINABLE;
        }

        return $codes;
    }

    /**
     * @param  list<AdministrativeReportPersonRecord>  $records
     * @param  list<array{person_id: string, national_id: string|null, full_name_ar: string|null}>  $indeterminate  Persons excluded as INDETERMINATE
     * @param  array<string, array{id: string, parent_id: string|null, name: string, is_active: bool, path: list<string>}>  $units
     * @param  array<string, int>  $population
     * @return array<string, mixed>
     */
    public static function build(array $records, array $indeterminate, array $units, array $population): array
    {
        $classifications = array_fill_keys(self::ADMINISTRATOR_CLASSIFICATIONS, []);
        $titles = [];
        $titleNotRecorded = [];
        $genders = ['MALE' => [], 'FEMALE' => [], 'NOT_RECORDED' => []];
        $placementDirect = [];
        $placementSubtree = [];
        $placementNotRecorded = [];
        $workplaces = [];
        $workplaceNotDeterminable = [];
        $dq = array_fill_keys(AdministrativeReportResult::DATA_QUALITY_CODES, []);

        foreach ($records as $record) {
            $pid = $record->personId;
            $genders[$record->gender['state'] === 'RECORDED' ? $record->gender['code'] : 'NOT_RECORDED'][$pid] = true;

            foreach ($record->relationships as $relationship) {
                foreach ($relationship['job_title_segments'] as $s) {
                    $classifications[$s['administrator_classification']][$pid] = true;
                    if ($s['state'] === 'RESOLVED') {
                        $titles[$s['job_title']['id']]['job_title'] = $s['job_title'];
                        $titles[$s['job_title']['id']]['persons'][$pid] = true;
                    } else {
                        $titleNotRecorded[$pid] = true;
                    }
                }
                foreach ($relationship['organizational_placement_segments'] as $s) {
                    if ($s['state'] !== 'RESOLVED') {
                        $placementNotRecorded[$pid] = true;

                        continue;
                    }
                    $placementDirect[$s['organizational_unit_id']][$pid] = true;
                    foreach ($units[$s['organizational_unit_id']]['path'] as $ancestorOrSelf) {
                        $placementSubtree[$ancestorOrSelf][$pid] = true;
                    }
                }
                foreach ($relationship['actual_workplace_segments'] as $s) {
                    if ($s['state'] !== 'RESOLVED') {
                        $workplaceNotDeterminable[$pid] = true;

                        continue;
                    }
                    $workplaces[$s['organizational_unit_id']]['persons'][$pid] = true;
                    $workplaces[$s['organizational_unit_id']]['types'][$s['movement_type']][$pid] = true;
                }
            }

            foreach ($record->dataQuality as $code) {
                $dq[$code][$pid] = ['person_id' => $pid, 'national_id' => $record->nationalId, 'full_name_ar' => $record->fullNameAr];
            }
        }
        foreach ($indeterminate as $person) {
            $dq[AdministrativeReportResult::DQ_INDETERMINATE_DUTY_STATE][$person['person_id']] = $person;
        }

        uasort($titles, fn (array $a, array $b) => [$a['job_title']['code'], $a['job_title']['id']] <=> [$b['job_title']['code'], $b['job_title']['id']]);

        $unitNode = fn (string $id) => ['unit_id' => $id, 'name' => $units[$id]['name'], 'parent_id' => $units[$id]['parent_id'], 'is_active' => $units[$id]['is_active'], 'depth' => count($units[$id]['path']) - 1, 'path' => $units[$id]['path']];
        $unitIds = array_keys($units);
        usort($unitIds, fn (string $a, string $b) => [implode('/', array_map(fn ($u) => $units[$u]['name'].'|'.$u, $units[$a]['path']))] <=> [implode('/', array_map(fn ($u) => $units[$u]['name'].'|'.$u, $units[$b]['path']))]);

        $workplaceIds = array_keys($workplaces);
        usort($workplaceIds, fn (string $a, string $b) => [$units[$a]['name'], $a] <=> [$units[$b]['name'], $b]);

        $dataQuality = [];
        foreach (AdministrativeReportResult::DATA_QUALITY_CODES as $code) {
            $persons = array_values($dq[$code]);
            usort($persons, fn (array $a, array $b) => $a['person_id'] <=> $b['person_id']);
            $dataQuality[] = ['code' => $code, 'person_count' => count($persons), 'persons' => $persons];
        }

        $genderBuckets = [];
        foreach ($genders as $code => $persons) {
            $genderBuckets[] = ['gender' => $code, 'person_count' => count($persons)];
        }

        return [
            'general_summary' => ['overall_headcount' => count($records), 'population' => $population],
            'administrator_classification' => array_map(fn (string $c) => ['classification' => $c, 'person_count' => count($classifications[$c])], self::ADMINISTRATOR_CLASSIFICATIONS),
            'job_titles' => [
                'titles' => array_values(array_map(fn (array $t) => ['job_title' => $t['job_title'], 'person_count' => count($t['persons'])], $titles)),
                'not_recorded_person_count' => count($titleNotRecorded),
            ],
            'gender' => $genderBuckets,
            'organizational_placement' => [
                'units' => array_map(fn (string $id) => $unitNode($id) + ['direct_person_count' => count($placementDirect[$id] ?? []), 'subtree_person_count' => count($placementSubtree[$id] ?? [])], $unitIds),
                'not_recorded_person_count' => count($placementNotRecorded),
            ],
            'actual_workplaces' => [
                'workplaces' => array_map(function (string $id) use ($unitNode, $workplaces) {
                    $types = $workplaces[$id]['types'];
                    ksort($types);

                    return $unitNode($id) + [
                        'person_count' => count($workplaces[$id]['persons']),
                        'movement_types' => array_map(fn (string $type, array $persons) => ['movement_type' => $type, 'person_count' => count($persons)], array_keys($types), array_values($types)),
                    ];
                }, $workplaceIds),
                'not_determinable_person_count' => count($workplaceNotDeterminable),
            ],
            'employee_drilldown' => ['person_count' => count($records), 'person_ids' => array_map(fn (AdministrativeReportPersonRecord $r) => $r->personId, $records)],
            'data_quality' => $dataQuality,
        ];
    }
}
