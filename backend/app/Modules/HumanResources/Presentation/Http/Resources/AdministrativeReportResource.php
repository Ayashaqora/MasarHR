<?php

namespace App\Modules\HumanResources\Presentation\Http\Resources;

use App\Modules\HumanResources\Application\Queries\Reporting\AdministrativeReportPersonRecord;
use App\Modules\HumanResources\Application\Queries\Reporting\AdministrativeReportResult;

/** Output shape of the R2 result (docs/administrative-report-foundation-specification.md §S42.13): a pure projection, no logic. */
final class AdministrativeReportResource
{
    /** @return array<string, mixed> */
    public static function toArray(string $month, AdministrativeReportResult $result): array
    {
        return [
            'month' => $month,
            'month_start' => $result->monthStart,
            'next_month_start' => $result->nextMonthStart,
            'month_end' => $result->monthEnd,
            'metadata' => [
                'label_semantics' => AdministrativeReportResult::LABEL_SEMANTICS,
                'semantics' => [
                    'population' => 'HAS_ON_DUTY_PERSONS_OF_THE_CANONICAL_S37_POPULATION',
                    'overall_headcount' => 'DISTINCT_PERSON',
                    'bucket_counts' => 'DISTINCT_PERSON_WITHIN_EACH_BUCKET',
                    'multi_value_sections_reconcile_to_overall' => false,
                    'job_title' => 'TEMPORAL_SEGMENTS',
                    'administrator_classification' => 'TEMPORAL_MAPPING_AS_OF_EACH_JOB_TITLE_SEGMENT',
                    'organizational_placement' => 'TEMPORAL_SEGMENTS_FULL_HIERARCHY',
                    'actual_workplace' => 'MOVEMENT_SEGMENTS_NOT_ATTENDANCE',
                    'partial_secondment_weekdays' => 'ALLOCATION_NOT_ATTENDANCE',
                    'gender' => 'CURRENT_RECORDED',
                    'person_identity' => 'CURRENT_RECORDED',
                ],
            ],
            'general_summary' => $result->sections['general_summary'],
            'administrator_classification' => $result->sections['administrator_classification'],
            'job_titles' => $result->sections['job_titles'],
            'gender' => $result->sections['gender'],
            'organizational_placement' => $result->sections['organizational_placement'],
            'actual_workplaces' => $result->sections['actual_workplaces'],
            'employee_drilldown' => $result->sections['employee_drilldown'],
            'data_quality' => $result->sections['data_quality'],
            'rows' => array_map(fn (AdministrativeReportPersonRecord $row) => [
                'person_id' => $row->personId,
                'national_id' => $row->nationalId,
                'full_name_ar' => $row->fullNameAr,
                'gender' => $row->gender,
                'relationships' => $row->relationships,
                'data_quality' => $row->dataQuality,
            ], $result->rows),
        ];
    }
}
