<?php

namespace App\Modules\HumanResources\Application\Queries\Reporting;

/**
 * Derives every official R4 section from the ONE canonical record collection (docs/employment-status-report-foundation-specification.md
 * §S43.9). It never recalculates the population, a status or a rule. Counting: the overall figure is a distinct-Person count; each
 * exposure bucket counts DISTINCT person_id exposed to that outcome at least once in the month (a Person may be in several buckets, so
 * the buckets need not sum to the overall figure); relationship and terminal-event figures are relationship grain. No percentage.
 */
final class EmploymentStatusSections
{
    /**
     * @param  list<EmploymentStatusReportPersonRecord>  $records
     * @param  list<array<string, mixed>>  $eventsOnly  ES-D56 terminal events of relationships outside the S37 population (they add no Person and no timeline)
     * @return array<string, mixed>
     */
    public static function build(array $records, array $eventsOnly = []): array
    {
        $person = fn (EmploymentStatusReportPersonRecord $r) => ['person_id' => $r->personId, 'national_id' => $r->nationalId, 'full_name_ar' => $r->fullNameAr];

        $exposure = [];
        foreach (EmploymentStatusReportResult::EXPOSURE_OUTCOMES as $outcome) {
            $exposure[$outcome] = ['persons' => [], 'relationships' => []];
        }
        $intention = [];
        foreach (EmploymentStatusReportResult::RETURN_INTENTION_OUTCOMES as $outcome) {
            $intention[$outcome] = ['persons' => [], 'relationships' => []];
        }
        $starts = [];
        $terminalEvents = [];
        $relationshipCount = 0;

        foreach ($records as $record) {
            foreach ($record->relationships as $relationship) {
                $relationshipCount++;
                $rid = $relationship['employment_relationship_id'];
                foreach ($relationship['status_segments'] as $s) {
                    $exposure[$s['outcome']]['persons'][$record->personId] = $person($record);
                    $exposure[$s['outcome']]['relationships'][$rid] = true;
                }
                foreach ($relationship['return_intention_segments'] as $s) {
                    $intention[$s['intention']]['persons'][$record->personId] = $person($record);
                    $intention[$s['intention']]['relationships'][$rid] = true;
                }
                if ($relationship['started_in_month']) {
                    $starts[] = ['person_id' => $record->personId, 'national_id' => $record->nationalId, 'full_name_ar' => $record->fullNameAr,
                        'employment_relationship_id' => $rid, 'start_date' => $relationship['effective_from'], 'employment_type_code' => $relationship['employment_type_code']];
                }
                if ($relationship['terminal_event'] !== null) {
                    $terminalEvents[] = ['person_id' => $record->personId, 'national_id' => $record->nationalId, 'full_name_ar' => $record->fullNameAr, 'employment_type_code' => $relationship['employment_type_code']]
                        + $relationship['terminal_event'] + ['relationship_in_monthly_population' => true];
                }
            }
        }

        foreach ($eventsOnly as $event) {
            $terminalEvents[] = $event;
        }
        usort($terminalEvents, fn (array $a, array $b) => [$a['person_id'], $a['event_date'], $a['employment_relationship_id']] <=> [$b['person_id'], $b['event_date'], $b['employment_relationship_id']]);

        $bucket = fn (string $key, string $name, array $b) => [$key => $name, 'person_count' => count($b['persons']), 'relationship_count' => count($b['relationships']), 'persons' => array_values($b['persons'])];
        $exposureSection = [];
        foreach ($exposure as $outcome => $b) {
            $exposureSection[] = $bucket('status', $outcome, $b);
        }
        $intentionSection = [];
        foreach ($intention as $outcome => $b) {
            $intentionSection[] = $bucket('intention', $outcome, $b);
        }

        $reasons = [];
        foreach ($terminalEvents as $event) {
            $reason = $event['reason']['state'] === 'RECORDED' ? strtoupper((string) $event['reason']['status_code']) : EmploymentStatusReportResult::REASON_NOT_RECORDED;
            $reasons[$reason]['relationships'][$event['employment_relationship_id']] = true;
            $reasons[$reason]['persons'][$event['person_id']] = true;
        }
        ksort($reasons);
        $byReason = [];
        foreach ($reasons as $reason => $b) {
            $byReason[] = ['reason' => $reason, 'relationship_count' => count($b['relationships']), 'person_count' => count($b['persons'])];
        }

        $dataQuality = [];
        foreach (EmploymentStatusReportResult::DATA_QUALITY_CODES as $code) {
            $persons = [];
            $relationships = [];
            foreach ($records as $record) {
                foreach ($record->relationships as $relationship) {
                    if (in_array($code, $relationship['data_quality'], true)) {
                        $persons[$record->personId] = $person($record);
                        $relationships[$relationship['employment_relationship_id']] = true;
                    }
                }
            }
            if ($code === EmploymentStatusReportResult::DQ_RELATIONSHIP_END_REASON_NOT_RECORDED) {
                foreach ($eventsOnly as $event) {
                    if ($event['reason']['state'] === EmploymentStatusReportResult::REASON_NOT_RECORDED) {
                        $persons[$event['person_id']] = ['person_id' => $event['person_id'], 'national_id' => $event['national_id'], 'full_name_ar' => $event['full_name_ar']];
                        $relationships[$event['employment_relationship_id']] = true;
                    }
                }
            }
            $dataQuality[] = ['code' => $code, 'person_count' => count($persons), 'relationship_count' => count($relationships), 'persons' => array_values($persons)];
        }

        $exposureCounts = [];
        foreach ($exposureSection as $b) {
            $exposureCounts[$b['status']] = $b['person_count'];
        }

        return [
            'general_summary' => [
                'overall_persons' => count($records),
                'relationships_represented' => $relationshipCount,
                'relationships_started' => count($starts),
                'relationships_ended' => count($terminalEvents),
                'status_exposure_person_counts' => $exposureCounts,
                'terminal_events_by_reason' => $byReason,
            ],
            'status_exposure' => $exposureSection,
            'relationship_starts' => ['count' => count($starts), 'items' => $starts],
            'relationship_terminal_events' => ['count' => count($terminalEvents), 'by_reason' => $byReason, 'items' => $terminalEvents],
            'return_intention' => $intentionSection,
            'employee_timeline' => array_map(fn (EmploymentStatusReportPersonRecord $r) => [
                'person_id' => $r->personId, 'national_id' => $r->nationalId, 'full_name_ar' => $r->fullNameAr,
                'duty_classification' => $r->dutyClassification, 'relationship_count' => count($r->relationships),
                'relationships' => $r->relationships, 'data_quality' => $r->dataQuality,
            ], $records),
            'data_quality' => $dataQuality,
        ];
    }
}
