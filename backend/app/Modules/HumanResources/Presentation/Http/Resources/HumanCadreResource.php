<?php

namespace App\Modules\HumanResources\Presentation\Http\Resources;

use App\Modules\HumanResources\Application\Queries\Reporting\HumanCadrePersonRecord;
use App\Modules\HumanResources\Application\Queries\Reporting\HumanCadreResult;

/** Output shape of the REPORT-1 result (docs/human-cadre-report-foundation-specification.md §S41.13): a pure projection, no logic. */
final class HumanCadreResource
{
    /** @return array<string, mixed> */
    public static function toArray(string $month, HumanCadreResult $result): array
    {
        return [
            'month' => $month,
            'month_start' => $result->monthStart,
            'next_month_start' => $result->nextMonthStart,
            'month_end' => $result->monthEnd,
            'metadata' => [
                'label_semantics' => HumanCadreResult::LABEL_SEMANTICS,
                'semantics' => [
                    'rows_are_unique_persons' => true,
                    'selected_relationship' => 'LATEST_QUALIFYING_RELATIONSHIP_IN_MONTH',
                    'classification_date' => 'MONTH_END_IF_ACTIVE_ELSE_LAST_ACTIVE_DATE',
                    'gender' => 'CURRENT_RECORDED',
                    'qualification' => 'CURRENT_RECORDED_PRIMARY',
                    'person_identity' => 'CURRENT_RECORDED',
                    'age' => 'COMPLETED_CALENDAR_YEARS_AT_MONTH_END',
                    'service' => 'ACTUAL_DOCUMENTED_SERVICE_DAYS',
                ],
            ],
            'overall_headcount' => $result->overallHeadcount,
            'cadre_summary' => $result->summaries['cadre'],
            'specialty_summary' => $result->summaries['specialty'],
            'employment_type_summary' => $result->summaries['employment_type'],
            'gender_summary' => $result->summaries['gender'],
            'qualification_summary' => $result->summaries['qualification'],
            'age_summary' => $result->summaries['age'],
            'service_summary' => $result->summaries['service'],
            'rows' => array_map(fn (HumanCadrePersonRecord $row) => [
                'person_id' => $row->personId,
                'national_id' => $row->nationalId,
                'full_name_ar' => $row->fullNameAr,
                'gender' => $row->gender,
                'selected_relationship' => $row->selectedRelationship,
                'classification_date' => $row->classificationDate,
                'specialty' => $row->specialty,
                'cadre' => $row->cadre,
                'qualification' => $row->qualification,
                'age' => $row->age,
                'service' => $row->service,
                'data_quality' => $row->dataQuality,
            ], $result->rows),
        ];
    }
}
