<?php

namespace App\Modules\HumanResources\Presentation\Http\Resources;

use App\Modules\HumanResources\Application\Queries\Reporting\MonthlyNotOnDutyResult;
use App\Modules\HumanResources\Application\Queries\Reporting\MonthlyRelationshipSegment;

/**
 * Output shape of GET /api/v1/hr/not-on-duty (docs/monthly-not-on-duty-report-foundation-specification.md §S39.14, §S39.14A):
 * month metadata, the summary, the REPORT-3 Person rows and the semantics disclosure. Ids, dates, stable codes and the existing
 * reference labels; the Person identity values (national_id, full_name) are display facts only. No age, no Person-level reason, no
 * single monthly workplace, no percentage.
 */
final class NotOnDutyResource
{
    /** @return array<string, mixed> */
    public static function toArray(string $requestedMonth, MonthlyNotOnDutyResult $result): array
    {
        return [
            'month' => $requestedMonth,
            'month_start' => $result->monthStart,
            'next_month_start' => $result->nextMonthStart,
            'summary' => [
                'total_not_on_duty_persons' => $result->totalNotOnDutyPersons,
                'indeterminate_count' => $result->indeterminateCount,
            ],
            'rows' => array_map(fn (array $row) => [
                'person' => $row['person'],
                'duty_classification' => $row['duty_classification'],
                'relationships' => array_map(fn (MonthlyRelationshipSegment $segment) => self::relationship($segment, $result->statusLabels), $row['relationships']),
            ], $result->rows),
            'semantics' => [
                'rows_are_unique_persons' => true,
                'reasons_scope' => 'PER_RELATIONSHIP',
                'workplace' => 'INTERVAL_SEGMENTS',
                'person_identity' => 'DISPLAY_FACTS_ONLY',
                'qualifications' => 'CURRENT_RECORDED_PERSON_FACTS',
                'indeterminate' => 'EXCLUDED_DATA_QUALITY_METADATA',
                'age' => 'NOT_EXPOSED',
            ],
        ];
    }

    /**
     * @param  array<string, array{code: string, name_ar: string, name_en: ?string}>  $labels
     * @return array<string, mixed>
     */
    private static function relationship(MonthlyRelationshipSegment $s, array $labels): array
    {
        $label = fn (?string $detailId, string $key) => $detailId === null ? null : ($labels[$detailId][$key] ?? null);
        $withLabels = fn (?array $reason) => $reason === null ? null : $reason + [
            'status_name_ar' => $label($reason['status_detail_id'], 'name_ar'),
            'status_name_en' => $label($reason['status_detail_id'], 'name_en'),
        ];

        return [
            'employment_relationship_id' => $s->employmentRelationshipId,
            'employment_type_id' => $s->employmentTypeId,
            'employment_type_code' => $s->employmentTypeCode,
            'employee_number_scheme' => $s->employeeNumberScheme,
            'effective_from' => $s->effectiveFrom,
            'effective_to' => $s->effectiveTo,
            'end_knowledge_state' => $s->endKnowledgeState,
            'end_is_uncertain' => $s->endIsUncertain,
            'ended_terminally' => $s->endedTerminally,
            'clipped_from' => $s->clippedFrom,
            'clipped_to' => $s->clippedTo,
            'status_segments' => array_map(fn (array $status) => $status + [
                'status_name_ar' => $label($status['status_detail_id'], 'name_ar'),
                'status_name_en' => $label($status['status_detail_id'], 'name_en'),
            ], $s->statusSegments),
            'last_non_on_duty_reason' => $withLabels($s->lastNonOnDutyReason),
            'relationship_end_reason' => $withLabels($s->relationshipEndReason),
            'workplace_segments' => $s->workplaceSegments,
            'work_schedule_segments' => $s->workScheduleSegments,
        ];
    }
}
