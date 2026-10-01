<?php

namespace Tests\Feature\HumanResources;

use App\Modules\HumanResources\Domain\CompletedAge;
use App\Modules\HumanResources\Domain\CumulativeServiceCalculator as Service;
use App\Modules\HumanResources\Domain\Exceptions\InconsistentDimensionHistoryException;
use PHPUnit\Framework\TestCase;

/**
 * S41 REPORT-1 pure engines (docs/human-cadre-report-foundation-specification.md §S41.9/§S41.10): the completed-calendar-years age and
 * the cumulative ACTUAL service in integer days. Pure — no database. The reporting boundary is 2026-12-01 (the report month is
 * November 2026), the age reference date 2026-11-30.
 */
class HumanCadreEnginesTest extends TestCase
{
    private const BOUNDARY = '2026-12-01';

    private const REF = '2026-11-30';

    // ------------------------------------------------------------------------------------------------------------
    // Age (R1-D41 / D50)
    // ------------------------------------------------------------------------------------------------------------

    public function test_every_age_band_boundary_uses_completed_calendar_years_at_the_reference_date(): void
    {
        $cases = [
            ['2002-11-30', 24, '<25'],   // turns 24 today
            ['2001-12-01', 24, '<25'],   // turns 25 tomorrow: still 24
            ['2001-11-30', 25, '25-34'], // the birthday is the reference date itself: completed
            ['1992-12-01', 33, '25-34'],
            ['1991-12-01', 34, '25-34'],
            ['1991-11-30', 35, '35-44'],
            ['1982-12-01', 43, '35-44'],
            ['1981-12-01', 44, '35-44'],
            ['1981-11-30', 45, '45-54'],
            ['1972-12-01', 53, '45-54'],
            ['1971-12-01', 54, '45-54'],
            ['1971-11-30', 55, '55-64'],
            ['1962-12-01', 63, '55-64'],
            ['1961-12-01', 64, '55-64'],
            ['1961-11-30', 65, '65+'],
            ['1900-01-01', 126, '65+'],
        ];

        foreach ($cases as [$birth, $years, $band]) {
            $age = CompletedAge::at($birth, self::REF);
            $this->assertSame(['CALCULABLE', $years, $band], [$age['state'], $age['years'], $age['band']], "born {$birth}");
        }
    }

    public function test_a_missing_birth_date_is_not_recorded_and_a_birth_after_the_report_date_is_not_calculable_never_negative(): void
    {
        $this->assertSame(['state' => 'NOT_RECORDED', 'years' => null, 'band' => 'NOT_RECORDED'], CompletedAge::at(null, self::REF));
        $this->assertSame(['state' => 'NOT_CALCULABLE', 'years' => null, 'band' => 'NOT_RECORDED'], CompletedAge::at('2026-12-01', self::REF), 'born the day after the reference date');
        $this->assertSame(['state' => 'NOT_CALCULABLE', 'years' => null, 'band' => 'NOT_RECORDED'], CompletedAge::at('2030-06-15', self::REF));
        $this->assertSame(['state' => 'CALCULABLE', 'years' => 0, 'band' => '<25'], CompletedAge::at(self::REF, self::REF), 'born on the reference date is age 0, not negative');
    }

    public function test_a_feb_29_birthday_has_its_anniversary_on_feb_29_in_a_leap_year_and_feb_28_otherwise(): void
    {
        $this->assertSame(27, CompletedAge::completedYears('2000-02-29', '2027-02-28'), 'non-leap year: the anniversary is Feb 28');
        $this->assertSame(26, CompletedAge::completedYears('2000-02-29', '2027-02-27'), 'the day before the Feb 28 anniversary');
        $this->assertSame(27, CompletedAge::completedYears('2000-02-29', '2028-02-28'), 'leap year: Feb 28 is before the Feb 29 anniversary');
        $this->assertSame(28, CompletedAge::completedYears('2000-02-29', '2028-02-29'), 'leap year: the anniversary is Feb 29');
        $this->assertSame(28, CompletedAge::completedYears('2000-02-29', '2028-03-01'));
        $this->assertSame(0, CompletedAge::completedYears('2024-02-29', '2025-02-27'));
        $this->assertSame(1, CompletedAge::completedYears('2024-02-29', '2025-02-28'));
    }

    // ------------------------------------------------------------------------------------------------------------
    // Service — helpers
    // ------------------------------------------------------------------------------------------------------------

    private function rel(string $id, string $from, ?string $to = null, string $state = 'NOT_APPLICABLE'): array
    {
        return ['id' => $id, 'effective_from' => $from, 'effective_to' => $to, 'end_knowledge_state' => $to === null ? $state : 'KNOWN'];
    }

    private function st(string $code, string $from, ?string $to = null, ?string $pay = null): array
    {
        return ['status_code' => $code, 'effective_from' => $from, 'effective_to' => $to, 'travel_pay_status' => $pay];
    }

    /** @return int the counted days of the Person for one relationship from 2026-01-01 (334 calendar days to the boundary) */
    private function days(array $statuses, ?string $end = null): int
    {
        $result = Service::calculate([$this->rel('r', '2026-01-01', $end)], ['r' => $statuses], self::BOUNDARY);
        $this->assertSame('CALCULABLE', $result['state']);

        return $result['service_days'];
    }

    // ------------------------------------------------------------------------------------------------------------
    // Service — baseline, relationships, status rules
    // ------------------------------------------------------------------------------------------------------------

    public function test_an_active_relationship_with_no_status_counts_every_day_to_the_report_boundary(): void
    {
        $this->assertSame(334, $this->days([]), '2026-01-01 .. 2026-11-30 inclusive: the boundary is exclusive');
        $this->assertSame(0, Service::calculate([$this->rel('r', '2026-12-01')], [], self::BOUNDARY)['service_days'], 'a relationship starting on the boundary adds nothing');
        $this->assertSame(1, Service::calculate([$this->rel('r', '2026-11-30')], [], self::BOUNDARY)['service_days'], 'one day: [11-30, 12-01)');
    }

    public function test_the_actual_end_controls_and_service_never_goes_beyond_the_report_boundary(): void
    {
        $this->assertSame(59, $this->days([], '2026-03-01'), '[01-01, 03-01) = 31 + 28; there is no other input that could extend it (an agreed contract term is not an input)');
        $this->assertSame(334, $this->days([], '2027-06-01'), 'an end after the boundary is clipped to it');
    }

    public function test_gaps_between_relationships_do_not_count_and_a_reappointment_adds_the_earlier_service(): void
    {
        $result = Service::calculate([
            $this->rel('b', '2026-06-01'),
            $this->rel('a', '2026-01-01', '2026-03-01'),
        ], [], self::BOUNDARY);

        $this->assertSame(59 + 183, $result['service_days'], '[01-01,03-01) = 59, gap 03-01..06-01 not counted, [06-01,12-01) = 183');
        $this->assertSame(['state' => 'CALCULABLE', 'reason' => null], ['state' => $result['state'], 'reason' => $result['reason']]);
    }

    public function test_adjacent_relationships_count_every_date_exactly_once(): void
    {
        $result = Service::calculate([$this->rel('a', '2026-01-01', '2026-06-01'), $this->rel('b', '2026-06-01')], [], self::BOUNDARY);

        $this->assertSame(334, $result['service_days']);
    }

    public function test_unpaid_leave_does_not_count_and_captive_suspended_and_unknown_statuses_do(): void
    {
        $this->assertSame(334 - 31, $this->days([$this->st('unpaid_leave', '2026-03-01', '2026-04-01')]));
        $this->assertSame(334, $this->days([$this->st('captive', '2026-03-01')]));
        $this->assertSame(334, $this->days([$this->st('suspended', '2026-03-01', '2026-04-01')]));
        $this->assertSame(334, $this->days([$this->st('on_duty', '2026-03-01')]));
        $this->assertSame(334, $this->days([$this->st('some_future_status', '2026-03-01', '2026-04-01')]), 'an unknown or future status counts by default');
    }

    public function test_travel_pay_status_decides_whether_traveling_counts_and_a_null_pay_is_flagged(): void
    {
        $period = ['2026-03-01', '2026-04-01'];

        $paid = Service::calculate([$this->rel('r', '2026-01-01')], ['r' => [$this->st('traveling', ...$period, pay: 'PAID')]], self::BOUNDARY);
        $unpaid = Service::calculate([$this->rel('r', '2026-01-01')], ['r' => [$this->st('traveling', ...$period, pay: 'UNPAID')]], self::BOUNDARY);
        $null = Service::calculate([$this->rel('r', '2026-01-01')], ['r' => [$this->st('traveling', ...$period, pay: null)]], self::BOUNDARY);

        $this->assertSame([334, false], [$paid['service_days'], $paid['travel_pay_not_recorded']], 'PAID_EXPLICIT counts, no warning');
        $this->assertSame([303, false], [$unpaid['service_days'], $unpaid['travel_pay_not_recorded']], 'UNPAID_EXPLICIT does not count, no warning');
        $this->assertSame([334, true], [$null['service_days'], $null['travel_pay_not_recorded']], 'PAID_BY_DEFAULT counts and is flagged');
    }

    // ------------------------------------------------------------------------------------------------------------
    // Service — external sick leave: 90 days per continuous run
    // ------------------------------------------------------------------------------------------------------------

    /** a sick period of $length days starting 2026-02-01 inside a relationship that otherwise counts */
    private function sick(int $length, string $start = '2026-02-01'): array
    {
        $end = (new \DateTimeImmutable($start))->modify("+{$length} days")->format('Y-m-d');

        return $this->st('external_sick_leave', $start, $end);
    }

    public function test_the_first_90_calendar_days_of_a_run_count_and_day_91_does_not(): void
    {
        $nonCounting = fn (int $length) => 334 - ($length - min($length, 90));

        $this->assertSame($nonCounting(89), $this->days([$this->sick(89)]), '89 days: all count');
        $this->assertSame($nonCounting(90), $this->days([$this->sick(90)]), 'exactly 90 days: all count');
        $this->assertSame(334 - 1, $this->days([$this->sick(91)]), '91 days: day 91 does not count');
        $this->assertSame(334 - 30, $this->days([$this->sick(120)]), '120 days: the last 30 do not');
    }

    public function test_the_90_day_allowance_is_the_half_open_interval_start_to_start_plus_90(): void
    {
        // run [2026-02-01, 2026-05-02) is 90 days (28 + 31 + 30 + 1): [start, start + 90) counts, [start + 90, run_end) does not.
        $this->assertSame(334, $this->days([$this->st('external_sick_leave', '2026-02-01', '2026-05-02')]), 'the whole 90-day run counts');
        $this->assertSame(334 - 1, $this->days([$this->st('external_sick_leave', '2026-02-01', '2026-05-03')]), 'one more day: [05-02, 05-03) does not');
    }

    public function test_adjacent_sick_periods_with_no_date_gap_are_one_run_with_one_allowance(): void
    {
        $adjacent = [$this->st('external_sick_leave', '2026-02-01', '2026-03-23'), $this->st('external_sick_leave', '2026-03-23', '2026-05-12')]; // 50 + 50 days
        $this->assertSame(334 - 10, $this->days($adjacent), '100 days in one run: 90 count, 10 do not');
    }

    public function test_a_date_gap_resets_the_allowance_so_each_run_counts_fully(): void
    {
        $withGap = [$this->st('external_sick_leave', '2026-02-01', '2026-03-23'), $this->st('external_sick_leave', '2026-03-24', '2026-05-13')]; // 50 + 50 days, one date between
        $this->assertSame(334, $this->days($withGap), 'two runs of 50 days: both count in full');
    }

    public function test_a_different_status_between_two_sick_periods_starts_a_new_run(): void
    {
        $statuses = [$this->st('external_sick_leave', '2026-02-01', '2026-04-02'), $this->st('suspended', '2026-04-02', '2026-04-07'), $this->st('external_sick_leave', '2026-04-07', '2026-06-07')]; // 60 + 5 + 61 days
        $this->assertSame(334 - 0, $this->days($statuses), '60 and 61 days, each below 90: the suspension separates the runs');
        $overRun = [$this->st('external_sick_leave', '2026-02-01', '2026-06-02'), $this->st('suspended', '2026-06-02', '2026-06-04'), $this->st('external_sick_leave', '2026-06-04', '2026-06-14')]; // 121 + 2 + 10
        $this->assertSame(334 - 31, $this->days($overRun), 'the first run loses its 31 excess days; the second 10-day run counts in full');
    }

    public function test_a_run_that_started_before_the_report_month_is_clipped_by_the_report_boundary(): void
    {
        // [2026-09-01, 2026-12-01) is 91 days (30 + 31 + 30): the run's 91st day (11-30) is excluded from the allowance.
        $this->assertSame(334 - 1, $this->days([$this->st('external_sick_leave', '2026-09-01', '2027-01-01')]), 'a period reaching past the boundary: counted only up to it, within the 90-day allowance');
        $this->assertSame(334 - 1 - 0, $this->days([$this->st('external_sick_leave', '2026-09-01', null)]));
    }

    public function test_the_relationship_end_clips_a_sick_run(): void
    {
        // the relationship ends 2026-04-01; the sick period would run to 2026-09-01 (212 days) but only [02-01, 04-01) = 59 days lies inside
        $this->assertSame(59, $this->days([$this->st('external_sick_leave', '2026-02-01', '2026-09-01')], '2026-04-01') - 31, 'baseline 31 days of January + the 59 sick days, all within the allowance');
        $this->assertSame(90, $this->days([$this->st('external_sick_leave', '2026-01-01', '2026-09-01')], '2026-06-01'), 'the 151-day clipped run counts only its first 90 days');
    }

    // ------------------------------------------------------------------------------------------------------------
    // Service — UNKNOWN_LEGACY, bands, display, corrupt history
    // ------------------------------------------------------------------------------------------------------------

    public function test_an_unknown_legacy_relationship_end_makes_the_service_incomplete_and_never_fabricates_an_end(): void
    {
        $result = Service::calculate([$this->rel('a', '2024-01-01', '2025-01-01'), $this->rel('b', '2026-01-01', null, 'UNKNOWN_LEGACY')], [], self::BOUNDARY);

        $this->assertSame(['INCOMPLETE', null, 'UNKNOWN_LEGACY_RELATIONSHIP_END'], [$result['state'], $result['service_days'], $result['reason']]);
    }

    public function test_service_bands_and_display_follow_floor_days_over_365(): void
    {
        $bands = [
            [0, '<5'], [364, '<5'], [365, '<5'], [1824, '<5'],
            [1825, '5-9'], [3649, '5-9'],
            [3650, '10-14'], [5474, '10-14'],
            [5475, '15-19'], [7299, '15-19'],
            [7300, '20-24'], [9124, '20-24'],
            [9125, '25-29'], [10949, '25-29'],
            [10950, '30+'], [20000, '30+'],
        ];
        foreach ($bands as [$days, $band]) {
            $this->assertSame($band, Service::breakdown($days)['band'], "{$days} days");
        }

        $this->assertSame(['years' => 5, 'days' => 175, 'band' => '5-9'], Service::breakdown(2000), '2000 days = 5 years + 175 days (no artificial months)');
        $this->assertSame(['years' => 0, 'days' => 364, 'band' => '<5'], Service::breakdown(364), 'under one year stays in <5');
        $this->assertSame(['years' => 1, 'days' => 0, 'band' => '<5'], Service::breakdown(365), 'the 365-day boundary is one completed year');
    }

    public function test_the_band_lists_are_the_official_ones(): void
    {
        $this->assertSame(['<5', '5-9', '10-14', '15-19', '20-24', '25-29', '30+', 'INCOMPLETE'], Service::BANDS);
        $this->assertSame(['<25', '25-34', '35-44', '45-54', '55-64', '65+', 'NOT_RECORDED'], CompletedAge::BANDS);
    }

    public function test_overlapping_relationships_or_statuses_fail_explicitly(): void
    {
        try {
            Service::calculate([$this->rel('a', '2026-01-01', '2026-06-01'), $this->rel('b', '2026-05-01')], [], self::BOUNDARY);
            $this->fail('overlapping relationships must fail');
        } catch (InconsistentDimensionHistoryException) {
            $this->addToAssertionCount(1);
        }

        $this->expectException(InconsistentDimensionHistoryException::class);
        $this->days([$this->st('suspended', '2026-03-01', '2026-05-01'), $this->st('unpaid_leave', '2026-04-01', '2026-06-01')]);
    }

    public function test_the_service_is_never_a_sum_of_independent_interval_durations(): void
    {
        // three disjoint intervals of 30 + 31 + 28 days: the canonical value is the plain day count, 89.
        $result = Service::calculate([
            $this->rel('a', '2026-01-01', '2026-01-31'),
            $this->rel('b', '2026-03-01', '2026-04-01'),
            $this->rel('c', '2026-05-01', '2026-05-29'),
        ], [], self::BOUNDARY);

        $this->assertSame(30 + 31 + 28, $result['service_days']);
        $this->assertIsInt($result['service_days']);
    }
}
