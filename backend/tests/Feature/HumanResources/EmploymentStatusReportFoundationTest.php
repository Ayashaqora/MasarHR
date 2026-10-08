<?php

namespace Tests\Feature\HumanResources;

use App\Modules\HumanResources\Application\Commands\CreateEmploymentRelationship;
use App\Modules\HumanResources\Application\Commands\EndEmploymentRelationship;
use App\Modules\HumanResources\Application\Commands\RecordEmploymentStatusPeriod;
use App\Modules\HumanResources\Application\Commands\RecordReturnIntention;
use App\Modules\HumanResources\Application\Queries\Reporting\BuildEmploymentStatusReportResult;
use App\Modules\HumanResources\Application\Queries\Reporting\EmploymentStatusReportPersonRecord;
use App\Modules\HumanResources\Application\Queries\Reporting\EmploymentStatusReportResult;
use App\Modules\HumanResources\Application\Queries\Reporting\ListMonthlyReportingPopulation;
use App\Modules\HumanResources\Domain\Exceptions\PersonIsTerminalException;
use App\Modules\HumanResources\Infrastructure\Authorization\HumanResourcesPermissionCatalog as Perm;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\EmploymentRelationship;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\Person;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/**
 * S43 R4 Monthly Employment Status report (docs/employment-status-report-foundation-specification.md): the COMPLETE canonical S37 population,
 * its S37 status segments interpreted (never rebuilt), Return Intention periods, relationship starts and terminal events, as ONE canonical
 * dataset from which every official section derives. Real PostgreSQL, synthetic data only. Report month: November 2026
 * [2026-11-01, 2026-12-01). Assertions about fixtures target the Persons this test creates; the reconciliation and consistency checks
 * hold for the WHOLE result.
 */
class EmploymentStatusReportFoundationTest extends HumanResourcesTestCase
{
    private const M = '2026-11-01';

    private const N = '2026-12-01';

    private const URL = '/api/v1/hr/employment-status-report';

    private const BUILDER = 'app/Modules/HumanResources/Application/Queries/Reporting/BuildEmploymentStatusReportResult.php';

    // ------------------------------------------------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------------------------------------------------

    private function report(string $month = self::M): EmploymentStatusReportResult
    {
        return app(BuildEmploymentStatusReportResult::class)($month);
    }

    /** A relationship with an explicit on_duty status recorded from 2026-10-01 (the S06 behavior epoch is 2026-09-26) (so the month starts ON_DUTY). */
    private function emp(string $from = '2026-01-01', string $type = 'permanent', ?Person $person = null, bool $onDuty = true): array
    {
        $person ??= $this->createPersonRecord();
        $rel = $this->createEmploymentRelationship($person, $type, null, $from);
        if ($onDuty) {
            $this->setStatus($person, $rel, 'on_duty', max('2026-10-01', date('Y-m-d', strtotime($from.' +1 day'))));
        }

        return [$person, $rel];
    }

    private function setStatus(Person $person, EmploymentRelationship $rel, string $code, string $from, ?string $to = null): void
    {
        app(RecordEmploymentStatusPeriod::class)->handle($person, $rel->refresh(), $this->statusDetail($code), $from, $to);
    }

    private function end(Person $person, EmploymentRelationship $rel, string $to, bool $terminal = false): void
    {
        $rel->refresh();
        app(EndEmploymentRelationship::class)->handle($person, $rel, $rel->version, $to, $terminal);
    }

    private function intention(EmploymentRelationship $rel, string $value, string $from, ?string $to = null): void
    {
        app(RecordReturnIntention::class)->handle($rel->refresh(), $value, $from, $to);
    }

    private function insertLegacy(Person $person, string $from): string
    {
        $id = (string) Str::uuid7();
        DB::table('hr.employment_relationships')->insert([
            'id' => $id, 'person_id' => $person->id, 'employment_type_id' => $this->employmentType('contract')->id,
            'employee_number' => 'CN-LEGACY-'.Str::upper(Str::random(8)), 'employee_number_scheme' => 'CONTRACT', 'effective_from' => $from,
            'effective_to' => null, 'end_knowledge_state' => 'UNKNOWN_LEGACY', 'version' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    private function recordOrNull(Person $person, string $month = self::M): ?EmploymentStatusReportPersonRecord
    {
        foreach ($this->report($month)->rows as $row) {
            if ($row->personId === $person->id) {
                return $row;
            }
        }

        return null;
    }

    private function record(Person $person, string $month = self::M): EmploymentStatusReportPersonRecord
    {
        $record = $this->recordOrNull($person, $month);
        $this->assertNotNull($record, 'the Person is in the R4 population');

        return $record;
    }

    private function rel(Person $person, int $i = 0): array
    {
        return $this->record($person)->relationships[$i];
    }

    /** @return list<array{0: string, 1: string, 2: string}> */
    private function segView(array $segments, string $key = 'outcome'): array
    {
        return array_map(fn (array $s) => [$s['from'], $s['to'], $s[$key]], $segments);
    }

    private function exposure(EmploymentStatusReportResult $r, string $status): array
    {
        foreach ($r->sections['status_exposure'] as $bucket) {
            if ($bucket['status'] === $status) {
                return $bucket;
            }
        }
        $this->fail("exposure bucket {$status} missing");
    }

    private function intentionBucket(EmploymentStatusReportResult $r, string $intention): array
    {
        foreach ($r->sections['return_intention'] as $bucket) {
            if ($bucket['intention'] === $intention) {
                return $bucket;
            }
        }
        $this->fail("intention bucket {$intention} missing");
    }

    private function dq(EmploymentStatusReportResult $r, string $code): array
    {
        foreach ($r->sections['data_quality'] as $entry) {
            if ($entry['code'] === $code) {
                return $entry;
            }
        }
        $this->fail("data-quality code {$code} missing");
    }

    private function inBucket(array $bucket, Person $person): bool
    {
        return in_array($person->id, array_column($bucket['persons'], 'person_id'), true);
    }

    /** @return list<string> every SQL statement issued while $fn runs */
    private function statements(callable $fn): array
    {
        $statements = [];
        DB::listen(function ($query) use (&$statements): void {
            $statements[] = $query->sql;
        });
        $fn();

        return $statements;
    }

    // ------------------------------------------------------------------------------------------------------------
    // 1. Population (S37, complete, no duty filter)
    // ------------------------------------------------------------------------------------------------------------

    public function test_the_population_is_the_complete_s37_population_of_every_duty_classification(): void
    {
        [$on] = $this->emp();
        [$captive, $captiveRel] = $this->emp(onDuty: false);
        $this->setStatus($captive, $captiveRel, 'captive', '2026-10-05');
        [$none] = $this->emp(onDuty: false);

        $r = $this->report();

        $this->assertSame('HAS_ON_DUTY', $this->record($on)->dutyClassification);
        $this->assertSame('NO_ON_DUTY', $this->record($captive)->dutyClassification, 'a NO_ON_DUTY Person is NOT excluded');
        $this->assertSame('INDETERMINATE', $this->record($none)->dutyClassification, 'an INDETERMINATE Person is NOT excluded');
        $this->assertSame(
            array_map(fn ($p) => $p->personId, app(ListMonthlyReportingPopulation::class)(self::M)->persons),
            array_map(fn ($row) => $row->personId, $r->rows),
            'R4 consumes exactly the S37 population, in the S37 order',
        );
        $this->assertSame(count($r->rows), $r->overallPersons);
        $this->assertSame(count($r->rows), $r->sections['general_summary']['overall_persons']);
    }

    public function test_relationship_overlap_follows_the_s37_half_open_semantics(): void
    {
        [$whole] = $this->emp('2026-01-01');
        [$startsMid] = $this->emp('2026-11-10');
        [$startsAtMonth] = $this->emp('2026-11-01');
        [$startsAtNext] = $this->emp(self::N, onDuty: false);
        [$endsMid, $endsMidRel] = $this->emp('2026-01-01');
        $this->end($endsMid, $endsMidRel, '2026-11-20');
        [$endsAtMonthStart, $a] = $this->emp('2026-01-01');
        $this->end($endsAtMonthStart, $a, self::M);
        [$endedBefore, $b] = $this->emp('2026-01-01');
        $this->end($endedBefore, $b, '2026-10-15');
        [$endsAtNext, $c] = $this->emp('2026-01-01');
        $this->end($endsAtNext, $c, self::N);

        $this->assertNotNull($this->recordOrNull($whole));
        $this->assertNotNull($this->recordOrNull($startsMid), 'a relationship beginning during the month is included');
        $this->assertNotNull($this->recordOrNull($startsAtMonth), 'effective_from == month_start is included');
        $this->assertNull($this->recordOrNull($startsAtNext), 'effective_from == next_month_start does not overlap the month');
        $this->assertNotNull($this->recordOrNull($endsMid), 'a relationship ending during the month is included');
        $this->assertNull($this->recordOrNull($endsAtMonthStart), 'effective_to == month_start does not overlap [from, to)');
        $this->assertNull($this->recordOrNull($endedBefore), 'a Person whose final relationship ended before the month is not included');
        $this->assertNotNull($this->recordOrNull($endsAtNext), 'effective_to == next_month_start covers the whole month');
    }

    public function test_one_person_with_two_qualifying_relationships_is_counted_once_and_both_are_preserved(): void
    {
        $person = $this->createPersonRecord();
        $a = $this->createEmploymentRelationship($person, 'permanent', null, '2026-01-01');
        $this->setStatus($person, $a, 'on_duty', '2026-10-01');
        $this->setStatus($person, $a, 'resigned', '2026-11-10');
        $b = $this->createEmploymentRelationship($person, 'contract', null, '2026-11-10');

        $r = $this->report();
        $record = $this->record($person);

        $this->assertCount(2, $record->relationships, 'every qualifying relationship stays represented — no "latest wins"');
        $this->assertSame([$a->id, $b->id], array_column($record->relationships, 'employment_relationship_id'));
        $this->assertCount(1, array_filter($r->rows, fn ($row) => $row->personId === $person->id), 'one record per Person');
        $this->assertSame(count($r->rows), $r->overallPersons);
        $this->assertSame(
            array_sum(array_map(fn ($row) => count($row->relationships), $r->rows)),
            $r->sections['general_summary']['relationships_represented'],
        );
        $this->assertGreaterThan($r->overallPersons - 1, $r->sections['general_summary']['relationships_represented'], 'relationship count is independent of the Person count');
        $this->assertSame(['2026-11-01', '2026-11-10'], [$record->relationships[0]['from'], $record->relationships[0]['to']], 'the ended relationship keeps its own window');
        $this->assertSame(['2026-11-10', '2026-12-01'], [$record->relationships[1]['from'], $record->relationships[1]['to']]);
        $this->assertSame('resigned', $record->relationships[0]['terminal_event']['reason']['status_code'], 'previous end history is preserved by the reappointment');
        $this->assertNull($record->relationships[1]['terminal_event']);
    }

    // ------------------------------------------------------------------------------------------------------------
    // 2. Status timeline (S37 segments interpreted, [from, to))
    // ------------------------------------------------------------------------------------------------------------

    public function test_explicit_on_duty_derived_on_duty_and_a_temporary_status_are_all_preserved(): void
    {
        [$person, $rel] = $this->emp();
        $this->setStatus($person, $rel, 'unpaid_leave', '2026-11-10', '2026-11-20');

        $segments = $this->rel($person)['status_segments'];

        $this->assertSame([
            ['2026-11-01', '2026-11-10', 'ON_DUTY'],
            ['2026-11-10', '2026-11-20', 'UNPAID_LEAVE'],
            ['2026-11-20', '2026-12-01', 'ON_DUTY'],
        ], $this->segView($segments));
        $this->assertSame(['EXPLICIT', 'EXPLICIT', 'DERIVED_ON_DUTY'], array_column($segments, 'kind'), 'explicit on_duty and the S32 derived return stay distinguishable');
        $this->assertNotNull($segments[2]['derived_from_status_period_id']);
        $this->assertSame($segments[1]['status_period_id'], $segments[2]['derived_from_status_period_id']);
        $this->assertNull($segments[2]['status_period_id'], 'the derived segment has no row of its own');
    }

    public function test_an_unresolved_status_gap_is_indeterminate_and_never_coerced(): void
    {
        [$person] = $this->emp(onDuty: false);

        $rel = $this->rel($person);

        $this->assertSame([['2026-11-01', '2026-12-01', 'INDETERMINATE']], $this->segView($rel['status_segments']));
        $this->assertSame('UNRESOLVED', $rel['status_segments'][0]['kind']);
        $this->assertContains(EmploymentStatusReportResult::DQ_INDETERMINATE_STATUS_COVERAGE, $rel['data_quality']);
        $this->assertContains($person->id, array_column($this->dq($this->report(), EmploymentStatusReportResult::DQ_INDETERMINATE_STATUS_COVERAGE)['persons'], 'person_id'));
    }

    public function test_two_separated_temporary_periods_keep_both_segments_and_the_person_is_exposed_once(): void
    {
        [$person, $rel] = $this->emp();
        $this->setStatus($person, $rel, 'traveling', '2026-11-02', '2026-11-05');
        $this->setStatus($person, $rel, 'traveling', '2026-11-10', '2026-11-15');

        $r = $this->report();

        $this->assertSame([
            ['2026-11-01', '2026-11-02', 'ON_DUTY'],
            ['2026-11-02', '2026-11-05', 'TRAVELING'],
            ['2026-11-05', '2026-11-10', 'ON_DUTY'],
            ['2026-11-10', '2026-11-15', 'TRAVELING'],
            ['2026-11-15', '2026-12-01', 'ON_DUTY'],
        ], $this->segView($this->rel($person)['status_segments']));
        $bucket = $this->exposure($r, 'TRAVELING');
        $this->assertSame(1, count(array_filter($bucket['persons'], fn ($p) => $p['person_id'] === $person->id)), 'two TRAVELING periods are ONE Person exposure');
        $this->assertSame(count($bucket['persons']), $bucket['person_count']);
    }

    public function test_every_temporary_status_type_is_an_exposure_outcome_with_its_own_lifecycle(): void
    {
        [$travelOpen, $r1] = $this->emp();
        $this->setStatus($travelOpen, $r1, 'traveling', '2026-11-10');
        [$travelBounded, $r2] = $this->emp();
        $this->setStatus($travelBounded, $r2, 'traveling', '2026-11-10', '2026-11-12');
        [$captive, $r3] = $this->emp();
        $this->setStatus($captive, $r3, 'captive', '2026-11-10');
        [$suspended, $r4] = $this->emp();
        $this->setStatus($suspended, $r4, 'suspended', '2026-11-10', '2026-11-18');
        [$unpaid, $r5] = $this->emp();
        $this->setStatus($unpaid, $r5, 'unpaid_leave', '2026-11-10', '2026-11-18');
        [$sick, $r6] = $this->emp();
        $this->setStatus($sick, $r6, 'external_sick_leave', '2026-11-10', '2026-11-18');

        $r = $this->report();

        $this->assertSame([['2026-11-10', '2026-12-01', 'TRAVELING']], $this->segView(array_slice($this->rel($travelOpen)['status_segments'], 1)), 'open-ended TRAVELING runs to the month end');
        $this->assertSame(['TRAVELING', 'ON_DUTY'], array_column(array_slice($this->rel($travelBounded)['status_segments'], 1), 'outcome'), 'a bounded TRAVELING returns to derived ON_DUTY');
        $this->assertSame([['2026-11-10', '2026-12-01', 'CAPTIVE']], $this->segView(array_slice($this->rel($captive)['status_segments'], 1)), 'CAPTIVE is open-ended only and never returns automatically');
        foreach ([[$suspended, 'SUSPENDED'], [$unpaid, 'UNPAID_LEAVE'], [$sick, 'EXTERNAL_SICK_LEAVE']] as [$person, $outcome]) {
            $this->assertSame([['2026-11-10', '2026-11-18', $outcome], ['2026-11-18', '2026-12-01', 'ON_DUTY']], $this->segView(array_slice($this->rel($person)['status_segments'], 1)));
            $this->assertTrue($this->inBucket($this->exposure($r, $outcome), $person));
        }
        $this->assertTrue($this->inBucket($this->exposure($r, 'TRAVELING'), $travelOpen));
        $this->assertTrue($this->inBucket($this->exposure($r, 'CAPTIVE'), $captive));
        $this->assertSame(
            array_map(fn ($b) => $b['status'], $r->sections['status_exposure']),
            EmploymentStatusReportResult::EXPOSURE_OUTCOMES,
            'every exposure outcome is always listed, in a fixed order',
        );
    }

    public function test_the_temporal_boundaries_are_half_open(): void
    {
        [$person, $rel] = $this->emp();
        $this->setStatus($person, $rel, 'suspended', '2026-11-10', '2026-11-20');

        $segments = $this->rel($person)['status_segments'];

        $this->assertSame('SUSPENDED', $segments[1]['outcome']);
        $this->assertSame('2026-11-20', $segments[1]['to'], 'the end date is exclusive');
        $this->assertSame(['2026-11-20', 'ON_DUTY'], [$segments[2]['from'], $segments[2]['outcome']], 'the day equal to effective_to is no longer SUSPENDED');
        $this->assertSame('2026-12-01', end($segments)['to']);
        $this->assertSame(self::N, $this->report()->nextMonthStart);
        $this->assertSame('2026-11-30', $this->report()->monthEnd);
    }

    public function test_a_month_start_boundary_status_is_clipped_to_the_month(): void
    {
        [$person, $rel] = $this->emp();
        $this->setStatus($person, $rel, 'unpaid_leave', '2026-10-20', '2026-11-05');

        $this->assertSame([['2026-11-01', '2026-11-05', 'UNPAID_LEAVE'], ['2026-11-05', '2026-12-01', 'ON_DUTY']], $this->segView($this->rel($person)['status_segments']));
    }

    // ------------------------------------------------------------------------------------------------------------
    // 3. Termination
    // ------------------------------------------------------------------------------------------------------------

    public function test_a_known_end_with_an_ending_status_is_a_terminal_event_dated_effective_to(): void
    {
        [$person, $rel] = $this->emp();
        $this->setStatus($person, $rel, 'resigned', '2026-11-20');

        $r = $this->report();
        $relationship = $this->rel($person);
        $event = $relationship['terminal_event'];

        $this->assertSame('2026-11-20', $event['event_date'], 'the terminal event date is effective_to');
        $this->assertSame('2026-11-19', $event['last_employed_day'], 'effective_to - 1 is only the last employed day');
        $this->assertSame(['state' => 'RECORDED', 'status_code' => 'resigned', 'status_period_id' => $event['reason']['status_period_id']], $event['reason']);
        $this->assertNotNull($event['reason']['status_period_id']);
        $this->assertFalse($event['ended_terminally'], 'the terminal flag is a separate fact');
        $this->assertSame('2026-11-20', $relationship['to'], 'the window ends at effective_to');
        $this->assertSame('2026-11-20', end($relationship['status_segments'])['to'], 'no synthetic INACTIVE segment from effective_to to the month end');
        $this->assertNotContains('INACTIVE', array_column($relationship['status_segments'], 'outcome'));
        $this->assertSame($r->sections['relationship_terminal_events']['count'], $r->sections['general_summary']['relationships_ended']);
        $this->assertContains($event['employment_relationship_id'], array_column($r->sections['relationship_terminal_events']['items'], 'employment_relationship_id'));
        $this->assertNotContains(EmploymentStatusReportResult::DQ_RELATIONSHIP_END_REASON_NOT_RECORDED, $relationship['data_quality']);
    }

    public function test_a_direct_known_end_without_a_reason_is_not_recorded_and_a_data_quality_finding(): void
    {
        [$person, $rel] = $this->emp();
        $this->end($person, $rel, '2026-11-20', terminal: true);

        $event = $this->rel($person)['terminal_event'];

        $this->assertSame('2026-11-20', $event['event_date']);
        $this->assertSame(['state' => 'NOT_RECORDED', 'status_code' => null, 'status_period_id' => null], $event['reason']);
        $this->assertTrue($event['ended_terminally'], 'ended_terminally is exposed but is NEVER the reason');
        $r = $this->report();
        $this->assertContains($person->id, array_column($this->dq($r, EmploymentStatusReportResult::DQ_RELATIONSHIP_END_REASON_NOT_RECORDED)['persons'], 'person_id'));
        $this->assertContains(EmploymentStatusReportResult::REASON_NOT_RECORDED, array_column($r->sections['relationship_terminal_events']['by_reason'], 'reason'));
        $this->assertContains(EmploymentStatusReportResult::DQ_RELATIONSHIP_END_REASON_NOT_RECORDED, $this->record($person)->dataQuality);
    }

    public function test_a_status_beginning_at_the_end_date_that_is_not_an_ending_status_is_not_a_reason(): void
    {
        [$person, $rel] = $this->emp();
        $this->setStatus($person, $rel, 'traveling', '2026-11-20');
        $this->end($person, $rel, '2026-11-20');

        $event = $this->rel($person)['terminal_event'];

        $this->assertSame('NOT_RECORDED', $event['reason']['state'], 'only an ended/terminal status beginning exactly at effective_to is the end reason');
        $this->assertContains(EmploymentStatusReportResult::DQ_RELATIONSHIP_END_REASON_NOT_RECORDED, $this->rel($person)['data_quality']);
    }

    public function test_the_event_belongs_to_its_own_month_only(): void
    {
        [$atNext, $a] = $this->emp();
        $this->setStatus($atNext, $a, 'resigned', self::N);
        [$mid, $b] = $this->emp();
        $this->setStatus($mid, $b, 'resigned', '2026-11-30');

        $r = $this->report();

        $this->assertNull($this->rel($atNext)['terminal_event'], 'effective_to == next_month_start is NEXT month\'s event');
        $this->assertSame('2026-12-01', $this->rel($atNext)['to'], 'the relationship still covers the whole month');
        $this->assertNotContains($a->id, array_column($r->sections['relationship_terminal_events']['items'], 'employment_relationship_id'));
        $this->assertSame('2026-11-30', $this->rel($mid)['terminal_event']['event_date'], 'the last day of the month is in the month');
        // ES-D56: the event dated next_month_start is listed in THAT month even though its relationship is outside that month's S37 population.
        $december = $this->report('2026-12-01');
        $this->assertNull($this->recordOrNull($atNext, '2026-12-01'), 'effective_to == month_start: the relationship is absent from the S37 population');
        $this->assertContains($a->id, array_column($december->sections['relationship_terminal_events']['items'], 'employment_relationship_id'));
    }

    public function test_a_terminal_event_dated_month_start_is_present_in_that_month_and_absent_from_the_previous_one(): void
    {
        [$person, $rel] = $this->emp();
        $this->setStatus($person, $rel, 'retired', '2026-12-01');

        $november = $this->report();
        $december = $this->report('2026-12-01');

        $this->assertNotContains($rel->id, array_column($november->sections['relationship_terminal_events']['items'], 'employment_relationship_id'), 'effective_to == next_month_start: not November\'s event');
        $this->assertNotNull($this->recordOrNull($person), 'the relationship still covers all of November');
        $this->assertNull($this->recordOrNull($person, '2026-12-01'), 'absent from December\'s S37 monthly population');
        $events = array_values(array_filter($december->sections['relationship_terminal_events']['items'], fn ($e) => $e['employment_relationship_id'] === $rel->id));
        $this->assertCount(1, $events, 'the event appears exactly once');
        $this->assertSame('2026-12-01', $events[0]['event_date']);
        $this->assertSame('2026-11-30', $events[0]['last_employed_day']);
        $this->assertSame(['state' => 'RECORDED', 'status_code' => 'retired', 'status_period_id' => $events[0]['reason']['status_period_id']], $events[0]['reason'], 'the end reason at the month-start boundary is preserved');
        $this->assertNotNull($events[0]['reason']['status_period_id']);
        $this->assertFalse($events[0]['relationship_in_monthly_population']);
        $this->assertSame(array_sum(array_map(fn ($b) => $b['relationship_count'], $december->sections['relationship_terminal_events']['by_reason'])), $december->sections['general_summary']['relationships_ended']);
    }

    public function test_an_event_only_person_is_not_added_to_overall_persons_and_has_no_timeline(): void
    {
        [$person, $rel] = $this->emp();
        $this->setStatus($person, $rel, 'resigned', '2026-12-01');
        $before = $this->report('2026-12-01');
        $overall = $before->overallPersons;

        $this->assertNull($this->recordOrNull($person, '2026-12-01'), 'no Person record');
        $this->assertNotContains($person->id, array_column($before->sections['employee_timeline'], 'person_id'), 'no manufactured status timeline');
        foreach ($before->sections['status_exposure'] as $bucket) {
            $this->assertNotContains($person->id, array_column($bucket['persons'], 'person_id'), 'no exposure for the post-employment month');
        }
        foreach ($before->sections['return_intention'] as $bucket) {
            $this->assertNotContains($person->id, array_column($bucket['persons'], 'person_id'), 'no manufactured Return Intention');
        }
        $this->assertSame(count($before->rows), $overall);
        $this->assertSame($overall, $before->sections['general_summary']['overall_persons']);
        $event = array_values(array_filter($before->sections['relationship_terminal_events']['items'], fn ($e) => $e['employment_relationship_id'] === $rel->id));
        $this->assertCount(1, $event);
        $this->assertSame($person->id, $event[0]['person_id']);
        $this->assertNotNull($event[0]['national_id']);

        $other = $this->createPersonRecord();
        $this->createEmploymentRelationship($other, 'permanent', null, '2026-01-01');
        $this->assertSame($overall + 1, $this->report('2026-12-01')->overallPersons, 'only a monthly-population Person changes Overall Persons');
        $this->assertSame(count($before->sections['relationship_terminal_events']['items']), count($this->report('2026-12-01')->sections['relationship_terminal_events']['items']));
    }

    public function test_a_person_with_an_event_at_month_start_and_a_new_relationship_stays_one_person_with_the_event_listed_once(): void
    {
        $person = $this->createPersonRecord();
        $a = $this->createEmploymentRelationship($person, 'permanent', null, '2026-01-01');
        $this->setStatus($person, $a, 'on_duty', '2026-10-01');
        $this->setStatus($person, $a, 'contract_ended', '2026-12-01');
        $b = $this->createEmploymentRelationship($person, 'contract', null, '2026-12-01');

        $december = $this->report('2026-12-01');
        $events = array_values(array_filter($december->sections['relationship_terminal_events']['items'], fn ($e) => $e['person_id'] === $person->id));

        $this->assertCount(1, array_filter($december->rows, fn ($row) => $row->personId === $person->id), 'in the population through the new relationship');
        $this->assertSame([$b->id], array_column($this->record($person, '2026-12-01')->relationships, 'employment_relationship_id'), 'the ended relationship is not a timeline relationship');
        $this->assertCount(1, $events);
        $this->assertSame($a->id, $events[0]['employment_relationship_id']);
        $this->assertFalse($events[0]['relationship_in_monthly_population']);
        $this->assertSame('contract_ended', $events[0]['reason']['status_code']);
    }

    public function test_a_direct_known_end_at_month_start_without_a_reason_is_not_recorded_with_data_quality(): void
    {
        [$person, $rel] = $this->emp();
        $this->end($person, $rel, '2026-12-01', terminal: true);

        $r = $this->report('2026-12-01');
        $events = array_values(array_filter($r->sections['relationship_terminal_events']['items'], fn ($e) => $e['employment_relationship_id'] === $rel->id));

        $this->assertCount(1, $events);
        $this->assertSame(['state' => 'NOT_RECORDED', 'status_code' => null, 'status_period_id' => null], $events[0]['reason']);
        $this->assertTrue($events[0]['ended_terminally'], 'ended_terminally is a separate fact, never the reason');
        $this->assertContains($person->id, array_column($this->dq($r, EmploymentStatusReportResult::DQ_RELATIONSHIP_END_REASON_NOT_RECORDED)['persons'], 'person_id'));
        $this->assertContains('NOT_RECORDED', array_column($r->sections['relationship_terminal_events']['by_reason'], 'reason'));
    }

    public function test_a_status_that_is_not_an_ending_status_at_a_month_start_end_is_not_a_reason(): void
    {
        [$person, $rel] = $this->emp();
        $this->setStatus($person, $rel, 'traveling', '2026-12-01');
        $this->end($person, $rel, '2026-12-01');

        $events = array_values(array_filter($this->report('2026-12-01')->sections['relationship_terminal_events']['items'], fn ($e) => $e['employment_relationship_id'] === $rel->id));

        $this->assertSame('NOT_RECORDED', $events[0]['reason']['state']);
    }

    public function test_unknown_legacy_cannot_enter_the_supplemental_terminal_event_query(): void
    {
        $person = $this->createPersonRecord();
        $id = $this->insertLegacy($person, '2026-01-01');

        foreach ([self::M, '2026-12-01'] as $month) {
            $this->assertNotContains($id, array_column($this->report($month)->sections['relationship_terminal_events']['items'], 'employment_relationship_id'), 'no known effective_to, no event');
        }
    }

    public function test_events_inside_and_at_the_start_of_the_month_are_all_reported_each_exactly_once(): void
    {
        [$mid, $a] = $this->emp();
        $this->setStatus($mid, $a, 'resigned', '2026-11-20');
        [$atStart, $b] = $this->emp();
        $this->setStatus($atStart, $b, 'retired', self::M);
        [$atNext, $c] = $this->emp();
        $this->setStatus($atNext, $c, 'deceased', self::N);

        $items = $this->report()->sections['relationship_terminal_events']['items'];
        $ids = array_column($items, 'employment_relationship_id');

        $this->assertContains($a->id, $ids);
        $this->assertContains($b->id, $ids, 'dated month_start: in this month');
        $this->assertNotContains($c->id, $ids, 'dated next_month_start: next month');
        $this->assertSame(count($ids), count(array_unique($ids)), 'no duplicate event');
    }

    public function test_the_existing_terminal_reasons_stay_distinct(): void
    {
        $codes = ['retired', 'resigned', 'contract_ended', 'martyred', 'deceased'];
        $people = [];
        foreach ($codes as $code) {
            [$person, $rel] = $this->emp();
            $this->setStatus($person, $rel, $code, '2026-11-15');
            $people[$code] = $person;
        }

        $r = $this->report();
        $byReason = array_column($r->sections['relationship_terminal_events']['by_reason'], 'relationship_count', 'reason');

        foreach ($codes as $code) {
            $this->assertSame($code, $this->rel($people[$code])['terminal_event']['reason']['status_code']);
            $this->assertGreaterThanOrEqual(1, $byReason[strtoupper($code)], "{$code} keeps its own summary row");
        }
        $this->assertTrue($this->rel($people['martyred'])['terminal_event']['ended_terminally']);
        $this->assertTrue($this->rel($people['deceased'])['terminal_event']['ended_terminally']);
        $this->assertFalse($this->rel($people['retired'])['terminal_event']['ended_terminally']);
        $this->assertSame($r->sections['relationship_terminal_events']['count'], $r->sections['general_summary']['relationships_ended']);
    }

    // ------------------------------------------------------------------------------------------------------------
    // 4. UNKNOWN_LEGACY
    // ------------------------------------------------------------------------------------------------------------

    public function test_unknown_legacy_keeps_the_end_unknown_and_never_fabricates_a_date(): void
    {
        $person = $this->createPersonRecord();
        $id = $this->insertLegacy($person, '2026-01-01');

        $r = $this->report();
        $rel = $this->rel($person);

        $this->assertSame($id, $rel['employment_relationship_id']);
        $this->assertSame('UNKNOWN_LEGACY', $rel['end_knowledge_state']);
        $this->assertNull($rel['effective_to'], 'no end date is inferred');
        $this->assertNull($rel['terminal_event'], 'an unknown end is not a terminal event');
        $this->assertSame(self::N, $rel['to'], 'the window is merely the month bound');
        $this->assertSame([['2026-11-01', '2026-12-01', 'INDETERMINATE']], $this->segView($rel['status_segments']));
        $this->assertSame('INDETERMINATE', $this->record($person)->dutyClassification);
        $this->assertContains($person->id, array_column($this->dq($r, EmploymentStatusReportResult::DQ_UNKNOWN_LEGACY_RELATIONSHIP_END)['persons'], 'person_id'));
        $this->assertContains($person->id, array_column($this->dq($r, EmploymentStatusReportResult::DQ_INDETERMINATE_STATUS_COVERAGE)['persons'], 'person_id'));
        $this->assertNotContains($id, array_column($r->sections['relationship_terminal_events']['items'], 'employment_relationship_id'));
    }

    public function test_unknown_legacy_with_explicit_statuses_keeps_them_and_still_flags_the_uncertain_coverage(): void
    {
        $person = $this->createPersonRecord();
        $id = $this->insertLegacy($person, '2026-01-01');
        DB::table('hr.employment_status_periods')->insert([
            'id' => (string) Str::uuid7(), 'employment_relationship_id' => $id, 'status_detail_id' => $this->statusDetail('on_duty')->id,
            'effective_from' => '2026-10-01', 'effective_to' => null, 'created_at' => now(),
        ]);

        $rel = $this->rel($person);

        $this->assertSame([['2026-11-01', '2026-12-01', 'ON_DUTY']], $this->segView($rel['status_segments']), 'S37\'s segments are not rewritten');
        $this->assertSame([EmploymentStatusReportResult::DQ_INDETERMINATE_STATUS_COVERAGE, EmploymentStatusReportResult::DQ_UNKNOWN_LEGACY_RELATIONSHIP_END], $rel['data_quality']);
    }

    // ------------------------------------------------------------------------------------------------------------
    // 5. Reappointment
    // ------------------------------------------------------------------------------------------------------------

    public function test_reappointment_in_the_same_month_keeps_independent_timelines(): void
    {
        $person = $this->createPersonRecord();
        $a = $this->createEmploymentRelationship($person, 'permanent', null, '2026-01-01');
        $this->setStatus($person, $a, 'on_duty', '2026-10-01');
        $this->setStatus($person, $a, 'contract_ended', '2026-11-08');
        $b = $this->createEmploymentRelationship($person, 'contract', null, '2026-11-15');
        $this->setStatus($person, $b, 'on_duty', '2026-11-20');
        $this->intention($b, 'WANTS_TO_RETURN', '2026-11-16');

        $record = $this->record($person);
        [$first, $second] = $record->relationships;

        $this->assertSame(1, count(array_filter($this->report()->rows, fn ($row) => $row->personId === $person->id)));
        $this->assertSame([$a->id, $b->id], [$first['employment_relationship_id'], $second['employment_relationship_id']]);
        $this->assertSame([['2026-11-01', '2026-11-08', 'ON_DUTY']], $this->segView($first['status_segments']));
        $this->assertSame([['2026-11-15', '2026-11-20', 'INDETERMINATE'], ['2026-11-20', '2026-12-01', 'ON_DUTY']], $this->segView($second['status_segments']), 'the new relationship does not inherit the old status');
        $this->assertSame([['2026-11-01', '2026-11-08', 'NOT_RECORDED']], $this->segView($first['return_intention_segments'], 'intention'));
        $this->assertSame([['2026-11-15', '2026-11-16', 'NOT_RECORDED'], ['2026-11-16', '2026-12-01', 'WANTS_TO_RETURN']], $this->segView($second['return_intention_segments'], 'intention'));
        $this->assertSame('contract_ended', $first['terminal_event']['reason']['status_code']);
        $this->assertTrue($second['started_in_month']);
        $this->assertFalse($first['started_in_month']);
        $this->assertCount(1, array_filter($this->report()->sections['relationship_starts']['items'], fn ($i) => $i['employment_relationship_id'] === $b->id), 'the reappointment start is included, with no FIRST/RE taxonomy');
        $this->assertSame(['person_id', 'national_id', 'full_name_ar', 'employment_relationship_id', 'start_date', 'employment_type_code'], array_keys($this->report()->sections['relationship_starts']['items'][0]));
    }

    public function test_a_terminal_person_cannot_be_reappointed(): void
    {
        [$person, $rel] = $this->emp();
        $this->setStatus($person, $rel, 'deceased', '2026-11-20');

        $this->assertTrue($person->refresh()->is_terminal);
        $this->expectException(PersonIsTerminalException::class);
        app(CreateEmploymentRelationship::class)->handle($person, $this->employmentType('contract'), '2026-11-25', null);
    }

    // ------------------------------------------------------------------------------------------------------------
    // 6. Return Intention
    // ------------------------------------------------------------------------------------------------------------

    public function test_return_intention_periods_are_temporal_segments_never_collapsed(): void
    {
        [$person, $rel] = $this->emp();
        $this->intention($rel, 'WANTS_TO_RETURN', '2026-11-05');
        $this->intention($rel, 'DOES_NOT_WANT_TO_RETURN', '2026-11-15');

        $r = $this->report();
        $segments = $this->rel($person)['return_intention_segments'];

        $this->assertSame([
            ['2026-11-01', '2026-11-05', 'NOT_RECORDED'],
            ['2026-11-05', '2026-11-15', 'WANTS_TO_RETURN'],
            ['2026-11-15', '2026-12-01', 'DOES_NOT_WANT_TO_RETURN'],
        ], $this->segView($segments, 'intention'));
        $this->assertNull($segments[0]['return_intention_period_id']);
        $this->assertNotNull($segments[1]['return_intention_period_id']);
        foreach (['WANTS_TO_RETURN', 'DOES_NOT_WANT_TO_RETURN', 'NOT_RECORDED'] as $bucket) {
            $this->assertTrue($this->inBucket($this->intentionBucket($r, $bucket), $person), "the Person is exposed to {$bucket}");
        }
        $this->assertSame(EmploymentStatusReportResult::RETURN_INTENTION_OUTCOMES, array_column($r->sections['return_intention'], 'intention'));
    }

    public function test_a_bounded_intention_leaves_a_not_recorded_gap_and_no_period_is_not_recorded(): void
    {
        [$person, $rel] = $this->emp();
        $this->intention($rel, 'WANTS_TO_RETURN', '2026-11-05', '2026-11-10');
        $this->intention($rel, 'DOES_NOT_WANT_TO_RETURN', '2026-11-20');
        [$never] = $this->emp();

        $this->assertSame([
            ['2026-11-01', '2026-11-05', 'NOT_RECORDED'],
            ['2026-11-05', '2026-11-10', 'WANTS_TO_RETURN'],
            ['2026-11-10', '2026-11-20', 'NOT_RECORDED'],
            ['2026-11-20', '2026-12-01', 'DOES_NOT_WANT_TO_RETURN'],
        ], $this->segView($this->rel($person)['return_intention_segments'], 'intention'));
        $this->assertSame([['2026-11-01', '2026-12-01', 'NOT_RECORDED']], $this->segView($this->rel($never)['return_intention_segments'], 'intention'), 'absence is NOT_RECORDED, never an error or a stored value');
        $this->assertSame(0, (int) DB::table('hr.return_intention_periods')->where('intention', 'NOT_RECORDED')->count());
    }

    public function test_a_missing_return_intention_is_never_a_data_quality_finding(): void
    {
        [$person] = $this->emp();
        $r = $this->report();

        $this->assertSame(['INDETERMINATE_STATUS_COVERAGE', 'UNKNOWN_LEGACY_RELATIONSHIP_END', 'RELATIONSHIP_END_REASON_NOT_RECORDED'], array_column($r->sections['data_quality'], 'code'));
        $this->assertSame(EmploymentStatusReportResult::DATA_QUALITY_CODES, array_column($r->sections['data_quality'], 'code'));
        $this->assertNotContains('RETURN_INTENTION_NOT_RECORDED', $this->record($person)->dataQuality);
        $this->assertStringNotContainsString('RETURN_INTENTION_NOT_RECORDED', json_encode($r->sections));
        $this->assertStringNotContainsString('RETURN_INTENTION_NOT_RECORDED', (string) file_get_contents(base_path(self::BUILDER)));
    }

    public function test_relationship_termination_truncates_the_intention_and_r4_reports_the_truncated_segment(): void
    {
        [$person, $rel] = $this->emp();
        $this->intention($rel, 'WANTS_TO_RETURN', '2026-11-05');
        $this->setStatus($person, $rel, 'resigned', '2026-11-20');

        $this->assertSame('2026-11-20', DB::table('hr.return_intention_periods')->where('employment_relationship_id', $rel->id)->value('effective_to'), 'S35 truncation is intact');
        $this->assertSame([['2026-11-01', '2026-11-05', 'NOT_RECORDED'], ['2026-11-05', '2026-11-20', 'WANTS_TO_RETURN']], $this->segView($this->rel($person)['return_intention_segments'], 'intention'));
    }

    public function test_return_intention_is_independent_of_status_exposure(): void
    {
        [$person, $rel] = $this->emp();
        $this->setStatus($person, $rel, 'traveling', '2026-11-10', '2026-11-12');
        $this->intention($rel, 'DOES_NOT_WANT_TO_RETURN', '2026-11-03', '2026-11-04');

        $before = $this->segView($this->rel($person)['status_segments']);
        $this->intention($rel, 'WANTS_TO_RETURN', '2026-11-20');

        $this->assertSame($before, $this->segView($this->rel($person)['status_segments']), 'recording an intention never changes a status');
    }

    // ------------------------------------------------------------------------------------------------------------
    // 7. Reconciliation
    // ------------------------------------------------------------------------------------------------------------

    public function test_exposure_buckets_are_distinct_person_counts_and_need_not_sum_to_the_overall_figure(): void
    {
        [$person, $rel] = $this->emp();
        $this->setStatus($person, $rel, 'traveling', '2026-11-02', '2026-11-05');
        $this->setStatus($person, $rel, 'suspended', '2026-11-10', '2026-11-15');
        $this->setStatus($person, $rel, 'traveling', '2026-11-20', '2026-11-25');

        $r = $this->report();
        $sum = array_sum(array_column($r->sections['status_exposure'], 'person_count'));

        foreach (['ON_DUTY', 'TRAVELING', 'SUSPENDED'] as $status) {
            $this->assertTrue($this->inBucket($this->exposure($r, $status), $person), "the Person appears in {$status}");
        }
        $this->assertGreaterThan($r->overallPersons, $sum, 'the buckets are multi-value and may exceed the overall figure');
        foreach ($r->sections['status_exposure'] as $bucket) {
            $this->assertSame(count(array_unique(array_column($bucket['persons'], 'person_id'))), $bucket['person_count'], 'DISTINCT person_id within the bucket');
            $this->assertLessThanOrEqual($r->overallPersons, $bucket['person_count']);
        }
        $this->assertSame(array_column($r->sections['status_exposure'], 'person_count', 'status'), $r->sections['general_summary']['status_exposure_person_counts']);
    }

    public function test_every_section_is_derived_from_the_same_canonical_records(): void
    {
        [$a, $ra] = $this->emp();
        $this->setStatus($a, $ra, 'captive', '2026-11-10');
        [$b, $rb] = $this->emp('2026-11-12');
        $this->setStatus($b, $rb, 'resigned', '2026-11-25');
        [$c] = $this->emp(onDuty: false);

        $r = $this->report();

        $this->assertSame(count($r->rows), $r->overallPersons);
        $this->assertSame(array_map(fn ($row) => $row->personId, $r->rows), array_column($r->sections['employee_timeline'], 'person_id'));
        $startItems = [];
        $eventItems = [];
        foreach ($r->rows as $row) {
            foreach ($row->relationships as $rel) {
                $rel['started_in_month'] && $startItems[] = $rel['employment_relationship_id'];
                $rel['terminal_event'] !== null && $eventItems[] = $rel['employment_relationship_id'];
            }
        }
        $this->assertSame($startItems, array_column($r->sections['relationship_starts']['items'], 'employment_relationship_id'));
        $this->assertSame($eventItems, array_column($r->sections['relationship_terminal_events']['items'], 'employment_relationship_id'));
        $this->assertSame(count($startItems), $r->sections['general_summary']['relationships_started']);
        $this->assertSame(count($eventItems), $r->sections['general_summary']['relationships_ended']);
        foreach ($r->sections['data_quality'] as $entry) {
            $expected = array_values(array_unique(array_map(fn ($row) => $row->personId, array_filter($r->rows, fn ($row) => in_array($entry['code'], $row->dataQuality, true)))));
            $this->assertSame($expected, array_column($entry['persons'], 'person_id'), $entry['code']);
            $this->assertSame(count($expected), $entry['person_count']);
        }
        $this->assertTrue($this->inBucket($this->exposure($r, 'CAPTIVE'), $a));
        $this->assertTrue($this->inBucket($this->exposure($r, 'INDETERMINATE'), $c));
        $this->assertContains($b->id, array_column(array_filter($r->sections['relationship_starts']['items'], fn ($i) => $i['start_date'] === '2026-11-12'), 'person_id'));
    }

    public function test_the_output_never_carries_a_percentage_or_a_day_count(): void
    {
        $this->emp();
        $this->viewer();
        $json = $this->getJson(self::URL.'?month='.self::M)->assertOk()->getContent();

        foreach (['percent', 'ratio', 'attendance', 'worked_days', 'absence', 'working_day', 'fte', 'payroll', 'balance'] as $forbidden) {
            $this->assertStringNotContainsStringIgnoringCase($forbidden, $json, "no {$forbidden}");
        }
    }

    // ------------------------------------------------------------------------------------------------------------
    // 8. Integrity
    // ------------------------------------------------------------------------------------------------------------

    public function test_the_database_rejects_overlapping_relationships_of_one_person(): void
    {
        [$person] = $this->emp('2026-01-01');

        $this->expectException(QueryException::class);
        DB::table('hr.employment_relationships')->insert([
            'id' => (string) Str::uuid7(), 'person_id' => $person->id, 'employment_type_id' => $this->employmentType('contract')->id,
            'employee_number' => $person->national_id, 'employee_number_scheme' => 'CONTRACT', 'effective_from' => '2026-11-10',
            'effective_to' => null, 'end_knowledge_state' => 'NOT_APPLICABLE', 'version' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_the_database_rejects_overlapping_status_periods_of_one_relationship(): void
    {
        [, $rel] = $this->emp();

        $this->expectException(QueryException::class);
        DB::table('hr.employment_status_periods')->insert([
            'id' => (string) Str::uuid7(), 'employment_relationship_id' => $rel->id, 'status_detail_id' => $this->statusDetail('traveling')->id,
            'effective_from' => '2026-11-10', 'effective_to' => '2026-11-12', 'created_at' => now(),
        ]);
    }

    // ------------------------------------------------------------------------------------------------------------
    // 9. API and security
    // ------------------------------------------------------------------------------------------------------------

    private function viewer(array $permissions = [Perm::MONTHLY_EMPLOYMENT_STATUS_REPORT_VIEW]): void
    {
        $principal = $this->createPrincipal();
        $this->assignRole($principal, $this->createRoleWithPermissions($permissions));
        $this->actingAs($principal, 'web');
    }

    public function test_the_endpoint_returns_metadata_sections_and_rows_from_one_computation(): void
    {
        [$person, $rel] = $this->emp();
        $this->setStatus($person, $rel, 'resigned', '2026-11-20');
        $this->viewer();

        $json = $this->getJson(self::URL.'?month='.self::M)->assertOk()->json();

        foreach (['month', 'month_start', 'next_month_start', 'month_end', 'metadata', 'general_summary', 'status_exposure', 'relationship_starts', 'relationship_terminal_events', 'return_intention', 'employee_timeline', 'data_quality', 'rows'] as $key) {
            $this->assertArrayHasKey($key, $json);
        }
        $this->assertSame(count($json['rows']), $json['general_summary']['overall_persons']);
        $this->assertSame('DISTINCT_PERSON', $json['metadata']['semantics']['overall_persons']);
        $this->assertFalse($json['metadata']['semantics']['status_buckets_reconcile_to_overall']);
        $this->assertFalse($json['metadata']['semantics']['terminal_events_reconcile_to_overall']);
        $this->assertSame('RELATIONSHIP_EFFECTIVE_TO', $json['metadata']['semantics']['terminal_event_date']);
        $this->assertSame(self::M, $json['month_start']);
        $this->assertSame('2026-11-30', $json['month_end']);
        $this->assertSame(array_column($json['rows'], 'person_id'), array_column($json['employee_timeline'], 'person_id'));
        $row = collect($json['rows'])->firstWhere('person_id', $person->id);
        $this->assertSame('2026-11-20', $row['relationships'][0]['terminal_event']['event_date']);
    }

    public function test_the_endpoint_requires_its_dedicated_permission_a_valid_month_and_authentication(): void
    {
        $this->getJson(self::URL.'?month='.self::M)->assertStatus(401);

        $this->viewer([Perm::MONTHLY_ADMINISTRATIVE_REPORT_VIEW, Perm::HUMAN_CADRE_VIEW, Perm::MONTHLY_NOT_ON_DUTY_VIEW, Perm::PERSONS_VIEW]);
        $this->getJson(self::URL.'?month='.self::M)->assertStatus(403);

        $this->viewer();
        $statements = $this->statements(function (): void {
            foreach (['', 'month=2026-11', 'month=2026-11-15', 'month=garbage', 'month=2026-13-01'] as $query) {
                $this->getJson(self::URL.($query === '' ? '' : '?'.$query))->assertStatus(422)->assertJsonValidationErrors(['month']);
            }
        });
        $this->assertCount(0, array_filter($statements, fn ($sql) => str_contains($sql, 'hr.employment_relationships')), 'an invalid month is rejected before S37 runs');
        $this->getJson(self::URL.'?month='.self::M)->assertOk();
    }

    public function test_the_permission_exists_and_is_granted_to_no_role(): void
    {
        $this->assertSame(1, (int) DB::table('security.permissions')->where('code', 'hr.monthly_employment_status_report.view')->count());
        $this->assertSame('hr.monthly_employment_status_report.view', Perm::MONTHLY_EMPLOYMENT_STATUS_REPORT_VIEW);
        $this->assertContains(Perm::MONTHLY_EMPLOYMENT_STATUS_REPORT_VIEW, Perm::ALL);
        $this->assertSame(0, (int) DB::table('security.role_permissions as rp')->join('security.permissions as p', 'p.id', '=', 'rp.permission_id')->where('p.code', 'hr.monthly_employment_status_report.view')->count(), 'existing permission is not auto-granted');
    }

    public function test_the_route_is_one_read_only_get_with_a_permission_and_no_pagination_filter_or_export_parameter(): void
    {
        $matches = array_values(array_filter(iterator_to_array(Route::getRoutes()), fn ($route) => $route->uri() === 'api/v1/hr/employment-status-report'));
        $this->assertCount(1, $matches);

        $this->assertSame(['GET', 'HEAD'], $matches[0]->methods());
        $this->assertContains('permission:'.Perm::MONTHLY_EMPLOYMENT_STATUS_REPORT_VIEW, $matches[0]->gatherMiddleware());
        $this->assertSame([], glob(base_path('app/Modules/*/Presentation/*/*Monthly*.php')), 'no Monthly* presentation class');

        $this->viewer();
        $plain = $this->getJson(self::URL.'?month='.self::M)->json('general_summary.overall_persons');
        $this->assertSame($plain, $this->getJson(self::URL.'?month='.self::M.'&per_page=1&page=2&sort=name&format=xlsx&status=TRAVELING&person_id='.Str::uuid7())->json('general_summary.overall_persons'), 'the only public input is month');
    }

    // ------------------------------------------------------------------------------------------------------------
    // 10. Query architecture
    // ------------------------------------------------------------------------------------------------------------

    public function test_the_statement_count_is_constant_independent_of_population_size(): void
    {
        $measure = function (int $people): array {
            for ($i = 0; $i < $people; $i++) {
                [$person, $rel] = $this->emp('2026-01-01', $i % 2 === 0 ? 'permanent' : 'contract');
                $this->setStatus($person, $rel, 'traveling', '2026-11-05', '2026-11-08');
                $this->setStatus($person, $rel, 'unpaid_leave', '2026-11-12', '2026-11-16');
                $this->intention($rel, 'WANTS_TO_RETURN', '2026-11-03');
                $this->intention($rel, 'DOES_NOT_WANT_TO_RETURN', '2026-11-18');
                $this->setStatus($person, $rel, 'resigned', '2026-11-25');
                [$p3, $r3] = $this->emp();
                $this->setStatus($p3, $r3, 'retired', self::M);
                [$p2] = $this->emp('2026-11-10', 'contract');
                $this->end($p2, EmploymentRelationship::query()->where('person_id', $p2->id)->first(), '2026-11-20');
            }

            return $this->statements(fn () => $this->report());
        };

        $small = $measure(2);
        $large = $measure(12);

        $this->assertCount(12, $small, 'S37 (8) + Person identity + Return Intention periods + supplemental terminal events + ending-status behaviors');
        $this->assertCount(count($small), $large, 'no N+1: the count does not depend on Persons, relationships, statuses, intentions or terminal events');
        foreach ($large as $sql) {
            $this->assertMatchesRegularExpression('/^(select|with)\\b/', strtolower(ltrim($sql)), 'read-only');
        }
        foreach (['FROM hr.persons p WHERE', 'FROM hr.return_intention_periods ri', 'FROM ref.employment_status_detail_behaviors b', 'LEFT JOIN hr.employment_status_periods sp ON'] as $needle) {
            $this->assertCount(1, array_filter($large, fn ($sql) => str_contains($sql, $needle)), "one batch: {$needle}");
        }
    }

    public function test_the_supplemental_terminal_event_query_is_one_bounded_batch(): void
    {
        $count = function (int $events) {
            for ($i = 0; $i < $events; $i++) {
                [$p, $r] = $this->emp();
                $this->setStatus($p, $r, 'retired', self::M);
            }
            $sql = array_values(array_filter($this->statements(fn () => $this->report()), fn ($q) => str_contains($q, 'LEFT JOIN hr.employment_status_periods sp ON')));

            return $sql;
        };

        $one = $count(1);
        $many = $count(9);

        $this->assertCount(1, $one);
        $this->assertCount(1, $many, 'one statement however many month-start events exist');
        $this->assertStringContainsString('r.id <> ALL(CAST(? AS uuid[]))', $many[0], 'bounded and deduplicated against the S37 relationships');
        $this->assertStringContainsString('r.effective_to >= ? AND r.effective_to < ?', $many[0]);
    }

    public function test_without_a_terminal_event_the_behavior_batch_is_skipped_and_the_count_stays_constant(): void
    {
        $count = function (int $people): int {
            for ($i = 0; $i < $people; $i++) {
                $this->emp();
            }

            return count($this->statements(fn () => $this->report()));
        };

        $a = $count(1);
        $b = $count(8);

        $this->assertSame($a, $b);
        $this->assertSame(11, $a);
    }

    public function test_s37_runs_exactly_once_and_no_per_relationship_resolver_is_used(): void
    {
        $this->emp();

        $statements = $this->statements(fn () => $this->report());

        $this->assertCount(1, array_filter($statements, fn ($sql) => str_contains($sql, 'SELECT r.id, r.person_id, r.employment_type_id')), 'the canonical S37 population is computed once');
        $this->assertCount(8, $this->statements(fn () => app(ListMonthlyReportingPopulation::class)(self::M)), 'S37 keeps its eight statements');
    }

    public function test_r4_contains_no_population_duty_status_or_forbidden_concepts_of_its_own(): void
    {
        $code = preg_replace('#/\*.*?\*/|//[^\n]*#s', '', (string) file_get_contents(base_path(self::BUILDER)));

        $this->assertSame(1, substr_count($code, '($this->population)('), 'ListMonthlyReportingPopulation is called exactly once');
        foreach (['MonthlyDutyClassification', 'MonthlyStatusSegmentation::segment(', 'MonthlyWorkplaceSegmentation', 'ListReportingPopulationAsOf', 'ResolveEffective', 'ResolveReturnIntentionAsOf', 'ResolveEmployment', 'ListMonthlyWorkforceDimensions', 'fromPopulation', 'DutyClassification'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $code, "R4 re-derives nothing: {$forbidden}");
        }
        $this->assertSame(4, substr_count($code, 'DB::select('), 'exactly the four R4 batches');
        $this->assertSame(1, substr_count($code, 'hr.employment_relationships'), 'the supplemental terminal-event batch is the only direct read of relationships');
        $this->assertSame(1, substr_count($code, 'hr.employment_status_periods'), 'and of status periods (the status beginning at effective_to)');
        foreach (['attendance', 'timesheet', 'fte', 'payroll', 'percent', 'xlsx', 'pdf', 'supervis', 'leave_balance'] as $excluded) {
            $this->assertStringNotContainsString($excluded, strtolower($code), "excluded concept: {$excluded}");
        }
    }

    public function test_s43_adds_no_business_schema_and_no_frontend_or_output_file(): void
    {
        $this->assertSame('2026_10_20_000001_seed_security_monthly_employment_status_report_permission.php', collect(glob(base_path('database/migrations/*.php')))->map('basename')->sort()->filter(fn ($name) => $name < '2026_10_21')->last(), 'the only S43 migration is the permission seed (the last one before S44\'s)');
        $this->assertSame(0, (int) DB::selectOne('select count(*) as c from pg_matviews')->c);
        // S48 (§S48.3) adds the one view the whole application reads qualifications through going
        // forward — unrelated to S43, which still adds no view/schema object of its own.
        $this->assertSame(['person_qualifications_current'], DB::table('information_schema.views')->whereIn('table_schema', ['hr', 'ref', 'org', 'automation', 'reporting'])->pluck('table_name')->all());
        $this->assertSame(0, DB::table('information_schema.columns')->where('table_schema', 'hr')->where('table_name', 'employment_relationships')->whereIn('column_name', ['end_reason', 'is_active', 'status', 'return_intention'])->count(), 'no convenience column');
        foreach (glob(base_path('../frontend/src/*/*.ts*')) ?: [] as $file) {
            $this->assertStringNotContainsString('employment-status-report', (string) file_get_contents($file), 'no frontend consumer');
        }
        foreach (['Pdf', 'Xlsx', 'Csv', 'Export', 'Dashboard', 'Chart', 'Print'] as $forbidden) {
            $this->assertSame([], array_values(array_filter(glob(base_path('app/Modules/HumanResources/*/*/*EmploymentStatusReport*')), fn ($f) => str_contains($f, $forbidden))));
        }
    }
}
