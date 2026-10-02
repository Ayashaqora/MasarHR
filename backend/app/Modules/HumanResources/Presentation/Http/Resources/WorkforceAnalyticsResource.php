<?php

namespace App\Modules\HumanResources\Presentation\Http\Resources;

use App\Modules\HumanResources\Application\Queries\Reporting\WorkforceAnalyticsResult;

/** Output shape of the S44 result (docs/workforce-analytics-foundation-specification.md §S44.13): a pure projection of the analytics sections, no logic and no Person rows. */
final class WorkforceAnalyticsResource
{
    /** @return array<string, mixed> */
    public static function toArray(string $month, WorkforceAnalyticsResult $result): array
    {
        return [
            'reporting_month' => $month,
            'month_start' => $result->monthStart,
            'next_month_start' => $result->nextMonthStart,
            'month_end' => $result->monthEnd,
            'metadata' => [
                'semantics' => [
                    'population' => 'THE_COMPLETE_CANONICAL_S37_POPULATION',
                    'overall_headcount' => 'DISTINCT_PERSON',
                    'single_value_dimensions' => 'EXACTLY_ONE_BUCKET_PER_PERSON_RECONCILES_TO_OVERALL_HEADCOUNT',
                    'multi_value_dimensions' => 'DISTINCT_PERSON_EXPOSED_AT_LEAST_ONCE_IN_THE_MONTH_MAY_EXCEED_OVERALL_HEADCOUNT',
                    'percentage_denominator' => WorkforceAnalyticsResult::DENOMINATOR_OVERALL_HEADCOUNT,
                    'multi_value_share' => WorkforceAnalyticsResult::SHARE_EXPOSED_SEMANTICS,
                    'relationship_starts_and_ends' => 'EVENT_GRAIN',
                    'relationship_end_event_date' => 'RELATIONSHIP_EFFECTIVE_TO',
                    'relationship_ends_population' => 'KNOWN_ENDS_DATED_IN_THE_MONTH_INDEPENDENT_OF_THE_S37_POPULATION',
                    'actual_workplace_weekdays' => 'ALLOCATION_NOT_ATTENDANCE',
                    'gender' => 'CURRENT_RECORDED',
                    'primary_qualification' => 'CURRENT_RECORDED',
                ],
            ],
            'population' => $result->sections['population'],
            'demographics' => $result->sections['demographics'],
            'employment' => $result->sections['employment'],
            'qualifications' => $result->sections['qualifications'],
            'organization' => $result->sections['organization'],
            'actual_work' => $result->sections['actual_work'],
            'employment_status' => $result->sections['employment_status'],
            'workforce_flows' => $result->sections['workforce_flows'],
            'data_quality' => $result->sections['data_quality'],
        ];
    }
}
