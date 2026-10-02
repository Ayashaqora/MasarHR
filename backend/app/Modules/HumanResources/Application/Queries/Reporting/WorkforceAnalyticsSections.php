<?php

namespace App\Modules\HumanResources\Application\Queries\Reporting;

use App\Modules\HumanResources\Domain\CompletedAge;
use App\Modules\HumanResources\Domain\CumulativeServiceCalculator;
use App\Modules\HumanResources\Domain\MonthlyDutyClassification;

/**
 * Derives every S44 analytics section from the ONE canonical record collection and the month's terminal events
 * (docs/workforce-analytics-foundation-specification.md §S44.7–§S44.11). It never recalculates the population, a status, a duty
 * classification or any other rule.
 *
 * Counting. overall_headcount is a distinct-Person count of the S37 population. A SINGLE-VALUE dimension (duty state, gender, age, Primary
 * Qualification, service) puts each Person in exactly one bucket, so its buckets reconcile to overall_headcount and its percentage is a
 * `percentage` over denominator OVERALL_HEADCOUNT. A MULTI-VALUE temporal dimension (relationship type, category, contract, specialty,
 * organizational placement, actual workplace, status exposure) counts DISTINCT person_id exposed to each bucket at least once in the month
 * (never segments or periods); a Person may be in several buckets, the bucket totals may exceed overall_headcount, and its figure is an
 * `exposure_share` whose semantics are SHARE_OF_OVERALL_POPULATION_EXPOSED_TO_BUCKET — it never implies the buckets partition the population.
 * Relationship starts and ends are EVENT-grain. Percentages are exact integer arithmetic (basis points, half up) and null for a zero denominator.
 */
final class WorkforceAnalyticsSections
{
    private const SPECIAL_STATES = ['NOT_RECORDED', 'NOT_APPLICABLE', 'UNMAPPED', 'INDETERMINATE'];

    private const WEEKDAYS = ['MONDAY', 'TUESDAY', 'WEDNESDAY', 'THURSDAY', 'FRIDAY', 'SATURDAY', 'SUNDAY'];

    /**
     * @param  list<WorkforceAnalyticsPersonRecord>  $records
     * @param  list<array<string, mixed>>  $events  every KNOWN end dated in the month (relationship_in_monthly_population distinguishes the event-only ones)
     * @param  array<string, array<string, mixed>>  $units  the referenced organizational units and their ancestors, each with its root → unit path
     * @return array<string, mixed>
     */
    public static function build(array $records, array $events, array $units): array
    {
        $overall = count($records);

        $relationshipCount = 0;
        $duty = [MonthlyDutyClassification::HAS_ON_DUTY => [], MonthlyDutyClassification::NO_ON_DUTY => [], MonthlyDutyClassification::INDETERMINATE => []];
        $genders = [];
        $ages = array_fill_keys(CompletedAge::BANDS, []);
        $ageStates = [CompletedAge::CALCULABLE => [], CompletedAge::NOT_RECORDED => [], CompletedAge::NOT_CALCULABLE => []];
        $services = array_fill_keys(CumulativeServiceCalculator::BANDS, []);
        $qualifications = [];
        $types = [];
        $categories = [];
        $contracts = [];
        $contractMappings = [];
        $specialties = [];
        $cadreMappings = [];
        $status = [];
        foreach (WorkforceAnalyticsResult::STATUS_OUTCOMES as $outcome) {
            $status[$outcome] = ['label' => ['status' => $outcome], 'state' => null, 'persons' => [], 'relationships' => []];
        }
        $placementDirect = [];
        $placementSubtree = [];
        $placementNotRecorded = [];
        $workplaces = [];
        $workplaceNotDeterminable = [];
        $starts = [];

        foreach ($records as $record) {
            $pid = $record->personId;
            $duty[$record->dutyClassification][$pid] = true;

            $genderKey = $record->gender['state'] === 'RECORDED' ? $record->gender['code'] : 'NOT_RECORDED';
            $genders[$genderKey] ??= ['label' => ['gender' => $genderKey, 'name_ar' => $record->gender['name_ar'], 'name_en' => $record->gender['name_en']], 'persons' => []];
            $genders[$genderKey]['persons'][$pid] = true;

            // WA-D69: the official band is exactly R1's (NOT_CALCULABLE is a calculation state whose band is NOT_RECORDED, never a bucket of its own).
            $ageKey = $record->age['band'];
            $ageStates[$record->age['state']][$pid] = true;
            $ages[$ageKey][$pid] = true;
            $services[$record->service['band']][$pid] = true;

            $q = $record->primaryQualification;
            $qualifications[$q['key']] ??= ['label' => ['academic_degree' => $q['academic_degree'], 'qualification_type' => $q['qualification_type']], 'state' => $q['state'], 'persons' => []];
            $qualifications[$q['key']]['persons'][$pid] = true;

            foreach ($record->relationships as $relationship) {
                $rid = $relationship['employment_relationship_id'];
                $relationshipCount++;

                $type = $relationship['employment_type'];
                $types[$type['code']] ??= ['label' => ['relationship_type' => $type['code'], 'name_ar' => $type['name_ar'], 'name_en' => $type['name_en']], 'state' => null, 'persons' => [], 'relationships' => []];
                self::expose($types[$type['code']], $pid, $rid);

                foreach ($relationship['category_segments'] as $s) {
                    self::exposeValue($categories, $s, $pid, $rid);
                }
                foreach ($relationship['contract_segments'] as $s) {
                    self::exposeValue($contracts, $s, $pid, $rid);
                    if ($s['population_mapping'] !== null) {
                        self::exposeMapping($contractMappings, $s['population_mapping'], $pid, $rid);
                    }
                }
                foreach ($relationship['specialty_segments'] as $s) {
                    self::exposeValue($specialties, $s, $pid, $rid);
                    if ($s['cadre_mapping'] !== null) {
                        self::exposeMapping($cadreMappings, $s['cadre_mapping'], $pid, $rid);
                    }
                }
                foreach ($relationship['status_segments'] as $s) {
                    $status[$s['outcome']] ??= ['label' => ['status' => $s['outcome']], 'state' => null, 'persons' => [], 'relationships' => []];
                    self::expose($status[$s['outcome']], $pid, $rid);
                }

                foreach ($relationship['placement_segments'] as $s) {
                    if ($s['state'] !== 'RESOLVED') {
                        $placementNotRecorded[$pid] = true;

                        continue;
                    }
                    $placementDirect[$s['organizational_unit_id']][$pid] = true;
                    foreach ($units[$s['organizational_unit_id']]['path'] as $ancestor) {
                        $placementSubtree[$ancestor][$pid] = true;
                    }
                }

                foreach ($relationship['actual_workplace_segments'] as $s) {
                    if ($s['state'] !== 'RESOLVED') {
                        $workplaceNotDeterminable[$s['state']][$pid] = true;

                        continue;
                    }
                    $unit = $s['organizational_unit_id'];
                    $workplaces[$unit]['persons'][$pid] = true;
                    $workplaces[$unit]['relationships'][$rid] = true;
                    $movement = ($s['underlying_of_partial_allocation'] ?? false) ? 'PLACEMENT_UNDERLYING_OF_PARTIAL_ALLOCATION' : $s['movement_type'];
                    $workplaces[$unit]['types'][$movement][$pid] = true;
                    foreach ($s['weekdays'] ?? [] as $weekday) {
                        $workplaces[$unit]['weekdays'][$weekday][$pid] = true;
                    }
                }

                if ($relationship['started_in_month']) {
                    $starts[] = ['person_id' => $pid, 'employment_relationship_id' => $rid, 'start_date' => $relationship['effective_from'], 'employment_type_code' => $type['code']];
                }
            }
        }

        $single = fn (array $buckets, callable $shape) => self::singleBuckets($buckets, $overall, $shape);

        $dutyBuckets = [];
        foreach ($duty as $classification => $persons) {
            $dutyBuckets[] = ['duty_state' => $classification] + self::counted(count($persons), $overall, 'percentage');
        }

        ksort($genders);
        uksort($genders, fn ($a, $b) => [$a === 'NOT_RECORDED', $a] <=> [$b === 'NOT_RECORDED', $b]);
        ksort($qualifications);
        uksort($qualifications, fn ($a, $b) => [$a === 'NOT_RECORDED', $a] <=> [$b === 'NOT_RECORDED', $b]);

        $genderBuckets = $single($genders, fn (array $b) => $b['label']);
        $ageBuckets = [];
        foreach ($ages as $band => $persons) {
            $ageBuckets[] = ['band' => $band] + self::counted(count($persons), $overall, 'percentage');
        }
        $serviceBuckets = [];
        foreach ($services as $band => $persons) {
            $serviceBuckets[] = ['band' => $band] + self::counted(count($persons), $overall, 'percentage');
        }
        $qualificationBuckets = $single($qualifications, fn (array $b) => $b['label'] + ['state' => $b['state']]);

        $placementUnits = self::placement($units, $placementDirect, $placementSubtree, $overall);

        $workplaceUnits = [];
        $unitIds = array_keys($workplaces);
        usort($unitIds, fn (string $a, string $b) => [$units[$a]['name'], $a] <=> [$units[$b]['name'], $b]);
        foreach ($unitIds as $unitId) {
            $w = $workplaces[$unitId];
            $movementTypes = [];
            ksort($w['types']);
            foreach ($w['types'] as $movement => $persons) {
                $movementTypes[] = ['movement_type' => $movement, 'person_count' => count($persons)];
            }
            $weekdays = [];
            foreach (self::WEEKDAYS as $weekday) {
                if (isset($w['weekdays'][$weekday])) {
                    $weekdays[] = ['weekday' => $weekday, 'person_count' => count($w['weekdays'][$weekday])];
                }
            }
            $workplaceUnits[] = [
                'unit_id' => $unitId, 'name' => $units[$unitId]['name'], 'path' => $units[$unitId]['path'],
                'person_count' => count($w['persons']), 'relationship_count' => count($w['relationships']),
                'exposure_share' => self::share(count($w['persons']), $overall),
                'movement_types' => $movementTypes, 'allocated_weekdays' => $weekdays,
            ];
        }
        $nonDeterminable = [];
        ksort($workplaceNotDeterminable);
        foreach ($workplaceNotDeterminable as $state => $persons) {
            $nonDeterminable[] = ['state' => $state, 'person_count' => count($persons), 'exposure_share' => self::share(count($persons), $overall)];
        }

        // Relationship ends: EVENT grain, every KNOWN end dated in the month — including the ones whose relationship S37 does not represent.
        $reasons = [];
        foreach ($events as $event) {
            $reason = $event['reason']['state'] === 'RECORDED' ? strtoupper((string) $event['reason']['status_code']) : EmploymentStatusReportResult::REASON_NOT_RECORDED;
            $reasons[$reason]['events'][$event['employment_relationship_id']] = true;
            $reasons[$reason]['persons'][$event['person_id']] = true;
        }
        ksort($reasons);
        $byReason = [];
        foreach ($reasons as $reason => $b) {
            $byReason[] = ['reason' => $reason, 'event_count' => count($b['events']), 'person_count' => count($b['persons'])];
        }
        $eventOnly = count(array_filter($events, fn (array $e) => ! $e['relationship_in_monthly_population']));

        $startTypes = [];
        foreach ($starts as $start) {
            $startTypes[$start['employment_type_code']]['events'][$start['employment_relationship_id']] = true;
            $startTypes[$start['employment_type_code']]['persons'][$start['person_id']] = true;
        }
        ksort($startTypes);

        return [
            'population' => [
                'overall_headcount' => $overall,
                'relationship_count' => $relationshipCount,
                'duty_state' => ['denominator_type' => WorkforceAnalyticsResult::DENOMINATOR_OVERALL_HEADCOUNT, 'denominator_value' => $overall, 'buckets' => $dutyBuckets, 'reconciles_to_overall_headcount' => array_sum(array_column($dutyBuckets, 'person_count')) === $overall],
            ],
            'demographics' => [
                'gender' => self::singleSection($genderBuckets, $overall, 'CURRENT_RECORDED'),
                'age' => self::singleSection($ageBuckets, $overall, 'COMPLETED_YEARS_AT_MONTH_END') + ['calculation_states' => array_map(fn (string $state, array $persons) => ['state' => $state, 'person_count' => count($persons)], array_keys($ageStates), array_values($ageStates))],
            ],
            'employment' => [
                'relationship_type' => self::multiSection($types, $overall),
                'employment_category' => self::multiSection($categories, $overall),
                'contract_dimension' => ['contract_types' => self::multiSection($contracts, $overall), 'population_mapping' => self::multiSection($contractMappings, $overall)],
                'service' => self::singleSection($serviceBuckets, $overall, 'PERSON_CUMULATIVE_SERVICE'),
            ],
            'qualifications' => [
                'primary_qualification' => self::singleSection($qualificationBuckets, $overall, 'CURRENT_RECORDED_PRIMARY'),
                'specialty' => ['specialties' => self::multiSection($specialties, $overall), 'cadre_mapping' => self::multiSection($cadreMappings, $overall)],
            ],
            'organization' => [
                'organizational_placement' => [
                    'semantics' => WorkforceAnalyticsResult::SHARE_EXPOSED_SEMANTICS, 'denominator_type' => WorkforceAnalyticsResult::DENOMINATOR_OVERALL_HEADCOUNT, 'denominator_value' => $overall,
                    'units' => $placementUnits, 'not_recorded_person_count' => count($placementNotRecorded), 'not_recorded_exposure_share' => self::share(count($placementNotRecorded), $overall),
                ],
            ],
            'actual_work' => [
                'actual_workplaces' => [
                    'semantics' => WorkforceAnalyticsResult::SHARE_EXPOSED_SEMANTICS, 'denominator_type' => WorkforceAnalyticsResult::DENOMINATOR_OVERALL_HEADCOUNT, 'denominator_value' => $overall,
                    'allocation_is_not_attendance' => true,
                    'workplaces' => $workplaceUnits, 'non_determinable' => $nonDeterminable,
                ],
            ],
            'employment_status' => ['status_exposure' => self::multiSection($status, $overall, false)],
            'workforce_flows' => [
                'relationship_starts' => [
                    'grain' => 'EVENT', 'event_count' => count($starts), 'person_count' => count(array_unique(array_column($starts, 'person_id'))),
                    'by_relationship_type' => array_map(fn (string $code, array $b) => ['relationship_type' => $code, 'event_count' => count($b['events']), 'person_count' => count($b['persons'])], array_keys($startTypes), array_values($startTypes)),
                    'items' => $starts,
                ],
                'relationship_ends' => [
                    'grain' => 'EVENT', 'event_date' => 'RELATIONSHIP_EFFECTIVE_TO', 'event_count' => count($events), 'in_monthly_population_event_count' => count($events) - $eventOnly, 'event_only_event_count' => $eventOnly,
                    'person_count' => count(array_unique(array_column($events, 'person_id'))), 'by_reason' => $byReason, 'items' => $events,
                ],
            ],
            'data_quality' => self::dataQuality($records, $events),
        ];
    }

    /** @return array{percentage?: array<string, mixed>, exposure_share?: array<string, mixed>, person_count: int} */
    private static function counted(int $personCount, int $overall, string $key): array
    {
        return ['person_count' => $personCount, $key => $key === 'percentage' ? self::percentage($personCount, $overall) : self::share($personCount, $overall)];
    }

    /** A single-value percentage: an exact share of OVERALL_HEADCOUNT in a partition of it. @return array<string, mixed> */
    private static function percentage(int $numerator, int $denominator): array
    {
        return ['numerator' => $numerator, 'denominator_type' => WorkforceAnalyticsResult::DENOMINATOR_OVERALL_HEADCOUNT, 'denominator_value' => $denominator] + self::ratio($numerator, $denominator);
    }

    /** A multi-value exposure share: it never implies mutually exclusive buckets and may sum above 100%. @return array<string, mixed> */
    private static function share(int $numerator, int $denominator): array
    {
        return ['semantics' => WorkforceAnalyticsResult::SHARE_EXPOSED_SEMANTICS, 'numerator' => $numerator, 'denominator_type' => WorkforceAnalyticsResult::DENOMINATOR_OVERALL_HEADCOUNT, 'denominator_value' => $denominator] + self::ratio($numerator, $denominator);
    }

    /** Exact integer basis points rounded half up; null (never a division) for a zero denominator. @return array{basis_points: int|null, percent: string|null} */
    private static function ratio(int $numerator, int $denominator): array
    {
        if ($denominator === 0) {
            return ['basis_points' => null, 'percent' => null];
        }
        $basisPoints = intdiv(2 * $numerator * 10000 + $denominator, 2 * $denominator);

        return ['basis_points' => $basisPoints, 'percent' => sprintf('%d.%02d', intdiv($basisPoints, 100), $basisPoints % 100)];
    }

    /**
     * @param  array<string, array<string, mixed>>  $buckets
     * @return list<array<string, mixed>>
     */
    private static function singleBuckets(array $buckets, int $overall, callable $shape): array
    {
        $out = [];
        foreach ($buckets as $b) {
            $out[] = $shape($b) + self::counted(count($b['persons']), $overall, 'percentage');
        }

        return $out;
    }

    /**
     * @param  list<array<string, mixed>>  $buckets
     * @return array<string, mixed>
     */
    private static function singleSection(array $buckets, int $overall, string $basis): array
    {
        return [
            'basis' => $basis, 'denominator_type' => WorkforceAnalyticsResult::DENOMINATOR_OVERALL_HEADCOUNT, 'denominator_value' => $overall,
            'buckets' => $buckets, 'reconciles_to_overall_headcount' => array_sum(array_column($buckets, 'person_count')) === $overall,
        ];
    }

    /**
     * @param  array<string, array<string, mixed>>  $buckets  key => label/state/persons/relationships
     * @return array<string, mixed>
     */
    private static function multiSection(array $buckets, int $overall, bool $sort = true): array
    {
        if ($sort) {
            uksort($buckets, function (string $a, string $b) use ($buckets) {
                $special = fn (string $key) => in_array($buckets[$key]['state'] ?? null, self::SPECIAL_STATES, true) || in_array($key, self::SPECIAL_STATES, true);

                return [$special($a), $a] <=> [$special($b), $b];
            });
        }
        $out = [];
        foreach ($buckets as $key => $b) {
            $out[] = ['bucket' => $key] + $b['label'] + ['state' => $b['state']] + [
                'person_count' => count($b['persons']), 'relationship_count' => count($b['relationships']), 'exposure_share' => self::share(count($b['persons']), $overall),
            ];
        }

        return [
            'grain' => 'MULTI_VALUE_TEMPORAL', 'semantics' => WorkforceAnalyticsResult::SHARE_EXPOSED_SEMANTICS,
            'denominator_type' => WorkforceAnalyticsResult::DENOMINATOR_OVERALL_HEADCOUNT, 'denominator_value' => $overall,
            'buckets_reconcile_to_overall_headcount' => false, 'buckets' => $out,
        ];
    }

    /** @param  array<string, mixed>  $bucket */
    private static function expose(array &$bucket, string $personId, string $relationshipId): void
    {
        $bucket['persons'][$personId] = true;
        $bucket['relationships'][$relationshipId] = true;
    }

    /**
     * A recorded value (RESOLVED) is its code; a missing one stays its own state (NOT_RECORDED / NOT_APPLICABLE) — never "other".
     *
     * @param  array<string, array<string, mixed>>  $buckets
     * @param  array<string, mixed>  $segment
     */
    private static function exposeValue(array &$buckets, array $segment, string $personId, string $relationshipId): void
    {
        $key = $segment['state'] === 'RESOLVED' ? (string) $segment['code'] : $segment['state'];
        $buckets[$key] ??= [
            'label' => $segment['state'] === 'RESOLVED' ? ['code' => $segment['code'], 'name_ar' => $segment['name_ar'], 'name_en' => $segment['name_en']] : ['code' => null, 'name_ar' => null, 'name_en' => null],
            'state' => $segment['state'], 'persons' => [], 'relationships' => [],
        ];
        self::expose($buckets[$key], $personId, $relationshipId);
    }

    /**
     * A reference mapping: RESOLVED is its target code, UNMAPPED stays UNMAPPED (never "other").
     *
     * @param  array<string, array<string, mixed>>  $buckets
     * @param  array<string, mixed>  $mapping
     */
    private static function exposeMapping(array &$buckets, array $mapping, string $personId, string $relationshipId): void
    {
        $key = $mapping['state'] === 'RESOLVED' ? (string) $mapping['code'] : $mapping['state'];
        $buckets[$key] ??= [
            'label' => $mapping['state'] === 'RESOLVED' ? ['code' => $mapping['code'], 'name_ar' => $mapping['name_ar'], 'name_en' => $mapping['name_en']] : ['code' => null, 'name_ar' => null, 'name_en' => null],
            'state' => $mapping['state'], 'persons' => [], 'relationships' => [],
        ];
        self::expose($buckets[$key], $personId, $relationshipId);
    }

    /**
     * @param  array<string, array<string, mixed>>  $units
     * @param  array<string, array<string, true>>  $direct
     * @param  array<string, array<string, true>>  $subtree
     * @return list<array<string, mixed>>
     */
    private static function placement(array $units, array $direct, array $subtree, int $overall): array
    {
        $ids = array_keys($units);
        usort($ids, fn (string $a, string $b) => [implode('/', array_map(fn ($u) => $units[$u]['name'].'|'.$u, $units[$a]['path']))] <=> [implode('/', array_map(fn ($u) => $units[$u]['name'].'|'.$u, $units[$b]['path']))]);

        $out = [];
        foreach ($ids as $id) {
            $out[] = [
                'unit_id' => $id, 'name' => $units[$id]['name'], 'parent_id' => $units[$id]['parent_id'], 'depth' => count($units[$id]['path']) - 1, 'path' => $units[$id]['path'],
                'direct_person_count' => count($direct[$id] ?? []), 'subtree_person_count' => count($subtree[$id] ?? []),
                'direct_exposure_share' => self::share(count($direct[$id] ?? []), $overall), 'subtree_exposure_share' => self::share(count($subtree[$id] ?? []), $overall),
            ];
        }

        return $out;
    }

    /**
     * @param  list<WorkforceAnalyticsPersonRecord>  $records
     * @param  list<array<string, mixed>>  $events
     * @return list<array<string, mixed>>
     */
    private static function dataQuality(array $records, array $events): array
    {
        $out = [];
        foreach (WorkforceAnalyticsResult::DATA_QUALITY_CODES as $code) {
            $persons = [];
            $relationships = [];
            if ($code === EmploymentStatusReportResult::DQ_RELATIONSHIP_END_REASON_NOT_RECORDED) {
                foreach ($events as $event) {
                    if ($event['reason']['state'] === EmploymentStatusReportResult::REASON_NOT_RECORDED) {
                        $persons[$event['person_id']] = true;
                        $relationships[$event['employment_relationship_id']] = true;
                    }
                }
            } elseif (in_array($code, WorkforceAnalyticsResult::RELATIONSHIP_GRAIN_CODES, true)) {
                foreach ($records as $record) {
                    foreach ($record->relationships as $relationship) {
                        if (in_array($code, $relationship['data_quality'], true)) {
                            $persons[$record->personId] = true;
                            $relationships[$relationship['employment_relationship_id']] = true;
                        }
                    }
                }
            } else {
                foreach ($records as $record) {
                    if (in_array($code, $record->dataQuality, true)) {
                        $persons[$record->personId] = true;
                    }
                }
            }
            $ids = array_keys($persons);
            sort($ids);
            $out[] = [
                'code' => $code,
                'grain' => in_array($code, WorkforceAnalyticsResult::RELATIONSHIP_GRAIN_CODES, true) ? 'RELATIONSHIP' : 'PERSON',
                'person_count' => count($ids),
                'relationship_count' => in_array($code, WorkforceAnalyticsResult::RELATIONSHIP_GRAIN_CODES, true) ? count($relationships) : null,
                'affected_person_ids' => $ids,
            ];
        }

        return $out;
    }
}
