<?php

namespace App\Modules\HumanResources\Presentation\Http\Resources;

use App\Modules\HumanResources\Application\Queries\Reporting\EmploymentStatusReportPersonRecord;
use App\Modules\HumanResources\Application\Queries\Reporting\EmploymentStatusReportResult;

/** Output shape of the R4 result (docs/employment-status-report-foundation-specification.md §S43.12): a pure projection, no logic. */
final class EmploymentStatusReportResource
{
    /** @return array<string, mixed> */
    public static function toArray(string $month, EmploymentStatusReportResult $result): array
    {
        return [
            'month' => $month,
            'month_start' => $result->monthStart,
            'next_month_start' => $result->nextMonthStart,
            'month_end' => $result->monthEnd,
            'metadata' => [
                'semantics' => [
                    'population' => 'THE_COMPLETE_CANONICAL_S37_POPULATION',
                    'overall_persons' => 'DISTINCT_PERSON',
                    'relationships_represented' => 'DISTINCT_RELATIONSHIP',
                    'status_exposure' => 'DISTINCT_PERSON_EXPOSED_AT_LEAST_ONCE_IN_THE_MONTH',
                    'status_buckets_reconcile_to_overall' => false,
                    'status_timeline' => 'S37_STATUS_SEGMENTS_HALF_OPEN',
                    'terminal_event_date' => 'RELATIONSHIP_EFFECTIVE_TO',
                    'return_intention' => 'TEMPORAL_SEGMENTS_NOT_RECORDED_IS_A_REPORTING_STATE',
                    'terminal_events_reconcile_to_overall' => false,
                    'terminal_events_population' => 'KNOWN_ENDS_DATED_IN_THE_MONTH_INDEPENDENT_OF_THE_S37_POPULATION',
                ],
            ],
            'general_summary' => $result->sections['general_summary'],
            'status_exposure' => $result->sections['status_exposure'],
            'relationship_starts' => $result->sections['relationship_starts'],
            'relationship_terminal_events' => $result->sections['relationship_terminal_events'],
            'return_intention' => $result->sections['return_intention'],
            'employee_timeline' => $result->sections['employee_timeline'],
            'data_quality' => $result->sections['data_quality'],
            'rows' => array_map(fn (EmploymentStatusReportPersonRecord $row) => [
                'person_id' => $row->personId,
                'national_id' => $row->nationalId,
                'full_name_ar' => $row->fullNameAr,
                'duty_classification' => $row->dutyClassification,
                'relationships' => $row->relationships,
                'data_quality' => $row->dataQuality,
            ], $result->rows),
        ];
    }
}
