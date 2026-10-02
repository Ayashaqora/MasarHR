<?php

namespace Tests\Feature\HumanResources;

use App\Modules\HumanResources\Application\Commands\EndEmploymentRelationship;
use App\Modules\HumanResources\Application\Commands\RecordEmploymentStatusPeriod;
use App\Modules\HumanResources\Application\Commands\RecordPartialSecondmentPeriod;
use App\Modules\HumanResources\Application\Commands\RecordWorkSchedulePeriod;
use App\Modules\HumanResources\Application\Commands\StartFullSecondment;
use App\Modules\HumanResources\Application\Commands\StartWorkplaceAssignment;
use App\Modules\HumanResources\Application\Queries\Reporting\BuildAdministrativeReportResult;
use App\Modules\HumanResources\Application\Queries\Reporting\BuildEmploymentStatusReportResult;
use App\Modules\HumanResources\Application\Queries\Reporting\BuildHumanCadreResult;
use App\Modules\HumanResources\Application\Queries\Reporting\BuildWorkforceAnalyticsResult;
use App\Modules\HumanResources\Infrastructure\Authorization\HumanResourcesPermissionCatalog as Perm;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\EmploymentRelationship;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\Person;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

/**
 * S45 Dashboard Foundation — the backend side of the contract (docs/dashboard-foundation-specification.md §S45.9): the Dashboard is a pure
 * consumer of the UNCHANGED S44 endpoint (Option A: no Dashboard endpoint, no new permission, no new query). These narrow tests protect
 * (1) that single-request composition and the aggregate-only/no-unsupported-KPI payload, (2) the S44-F1 accepted duplication — S44's R2
 * workplace and R4 terminal-reason semantics must keep agreeing with the authoritative R2/R4 builders — and (3) the INTENTIONAL differences
 * between S44 and R1/R2 (population, relationship-type grain) so nobody "fixes" them to reconcile. Real PostgreSQL, synthetic data only.
 * Report month: November 2026 [2026-11-01, 2026-12-01).
 */
class DashboardSemanticConsistencyTest extends HumanResourcesTestCase
{
    private const M = '2026-11-01';

    private const URL = '/api/v1/hr/workforce-analytics';

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

    private function end(Person $person, EmploymentRelationship $rel, string $to): void
    {
        $rel->refresh();
        app(EndEmploymentRelationship::class)->handle($person, $rel, $rel->version, $to, false);
    }

    private function viewer(array $permissions = [Perm::WORKFORCE_ANALYTICS_VIEW]): void
    {
        $principal = $this->createPrincipal();
        $this->assignRole($principal, $this->createRoleWithPermissions($permissions));
        $this->actingAs($principal, 'web');
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

    /** A month exercising workplaces (every movement type), statuses, ends (incl. month-start) and starts. */
    private function mixedMonth(): array
    {
        $home = $this->createUnit('الأصل');
        $dFull = $this->createUnit('إعارة');
        $dAssign = $this->createUnit('تكليف');
        $dPartial = $this->createUnit('جزئية');

        [$full, $fullRel] = $this->emp();
        $this->recordPlacement($fullRel, $home, '2026-02-01');
        app(StartFullSecondment::class)->handle($fullRel->refresh(), $dFull, '2026-11-10');

        [, $assignRel] = $this->emp();
        $this->recordPlacement($assignRel, $home, '2026-02-01');
        app(StartWorkplaceAssignment::class)->handle($assignRel->refresh(), $dAssign, '2026-11-12', $this->assignmentDecisionType());

        [, $partialRel] = $this->emp();
        $this->recordPlacement($partialRel, $home, '2026-02-01');
        app(RecordWorkSchedulePeriod::class)->handle($partialRel, '2026-01-01', ['SUNDAY', 'MONDAY', 'TUESDAY', 'WEDNESDAY', 'THURSDAY']);
        app(RecordPartialSecondmentPeriod::class)->handle($partialRel->refresh(), $dPartial, '2026-11-10', '2026-11-24', ['SUNDAY', 'TUESDAY']);

        [$traveler, $travelRel] = $this->emp();
        $this->setStatus($traveler, $travelRel, 'traveling', '2026-11-05', '2026-11-08');
        [$resigned, $resignedRel] = $this->emp();
        $this->setStatus($resigned, $resignedRel, 'resigned', '2026-11-20');
        [$direct, $directRel] = $this->emp();
        $this->end($direct, $directRel, '2026-11-25');
        [$retired, $retiredRel] = $this->emp();
        $this->setStatus($retired, $retiredRel, 'retired', self::M); // month-start end: event-only, outside the S37 population
        [, $zeroRel] = $this->emp();
        $this->recordPlacement($zeroRel, $home, '2026-02-01');
        app(RecordWorkSchedulePeriod::class)->handle($zeroRel, '2026-01-01', ['SUNDAY', 'MONDAY', 'TUESDAY', 'WEDNESDAY', 'THURSDAY']);
        app(RecordPartialSecondmentPeriod::class)->handle($zeroRel->refresh(), $this->createUnit('بلا أيام'), '2026-11-10', '2026-11-12', ['SUNDAY']); // zero applicable weekdays: no destination occurrence
        [$notReason, $notReasonRel] = $this->emp();
        $this->setStatus($notReason, $notReasonRel, 'traveling', '2026-11-20');
        $this->end($notReason, $notReasonRel, '2026-11-20'); // a status at the end date that is not an ending status is not a reason
        [$noDuty, $noDutyRel] = $this->emp(onDuty: false);
        $this->setStatus($noDuty, $noDutyRel, 'captive', '2026-10-05');
        $this->emp(onDuty: false); // INDETERMINATE
        $this->emp('2026-11-10');  // a start in the month

        return [$full, $retired];
    }

    // ------------------------------------------------------------------------------------------------------------
    // 1. Single-request composition over the UNCHANGED S44 endpoint (Option A)
    // ------------------------------------------------------------------------------------------------------------

    public function test_option_a_the_dashboard_adds_no_endpoint_no_permission_and_no_migration(): void
    {
        foreach (Route::getRoutes() as $route) {
            $this->assertStringNotContainsStringIgnoringCase('dashboard', $route->uri(), 'S45 adds no Dashboard route: the S44 endpoint serves the page');
        }
        $this->assertSame(0, (int) DB::table('security.permissions')->where('code', 'like', '%dashboard%')->count(), 'no Dashboard permission: hr.workforce_analytics.view is reused');
        $this->assertSame('2026_10_21_000001_seed_security_workforce_analytics_permission.php', collect(glob(base_path('database/migrations/*.php')))->map('basename')->sort()->last(), 'no S45 migration');
        $this->assertCount(92, glob(base_path('database/migrations/*.php')));
        $this->assertSame([], glob(base_path('app/Modules/*/*/*/*Dashboard*.php')), 'no Dashboard backend class');

        $s44 = array_values(array_filter(iterator_to_array(Route::getRoutes()), fn ($route) => $route->uri() === 'api/v1/hr/workforce-analytics'));
        $this->assertCount(1, $s44);
        $this->assertContains('permission:'.Perm::WORKFORCE_ANALYTICS_VIEW, $s44[0]->gatherMiddleware());
    }

    public function test_the_page_level_request_runs_s37_once_and_its_statement_count_is_constant(): void
    {
        $this->viewer();
        $measure = function (int $people): array {
            for ($i = 0; $i < $people; $i++) {
                [$p, $rel] = $this->emp();
                $this->recordPlacement($rel, $this->createUnit('م'.$i), '2026-02-01');
                $this->setStatus($p, $rel, 'traveling', '2026-11-05', '2026-11-08');
                [$q, $qRel] = $this->emp();
                $this->setStatus($q, $qRel, 'retired', self::M);
            }

            return $this->statements(fn () => $this->getJson(self::URL.'?month='.self::M)->assertOk());
        };

        $small = $measure(2);
        $large = $measure(10);

        $this->assertCount(count($small), $large, 'one canonical computation per page request: no N+1 and no per-widget growth');
        $this->assertCount(1, array_filter($large, fn ($sql) => str_contains($sql, 'SELECT r.id, r.person_id, r.employment_type_id')), 'S37 executes exactly once for the Dashboard request');
        $this->assertCount(1, array_filter($large, fn ($sql) => str_contains($sql, 'FROM hr.employment_category_periods p')), 'S40 consumes the precomputed population');
    }

    public function test_the_payload_is_aggregate_only_and_carries_no_unsupported_kpi(): void
    {
        $this->mixedMonth();
        $this->viewer();

        $json = $this->getJson(self::URL.'?month='.self::M)->assertOk()->json();

        $this->assertSame(
            ['actual_work', 'data_quality', 'demographics', 'employment', 'employment_status', 'metadata', 'month_end', 'month_start', 'next_month_start', 'organization', 'population', 'qualifications', 'reporting_month', 'workforce_flows'],
            collect(array_keys($json))->sort()->values()->all(),
            'the exact response contract the Dashboard frontend types mirror',
        );
        $keys = [];
        array_walk_recursive($json, function ($value, $key) use (&$keys): void {
            $keys[] = (string) $key;
        });
        $flat = strtolower(json_encode($json));
        foreach (['national_id', 'full_name', 'person_name', 'attendance_rate', 'absence', 'leave_utilization', 'leave_balance', 'vacancy', 'occupancy', 'turnover', 'productivity', 'payroll', 'forecast', '"hires"'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $flat, "no {$forbidden}");
        }
        foreach ($keys as $key) {
            $this->assertNotContains(strtolower($key), ['fte', 'rows', 'national_id', 'full_name_ar', 'hires', 'turnover_rate'], "no {$key} key");
        }
        array_walk_recursive($json, function ($value, $key): void {
            if ($key === 'buckets_reconcile_to_overall_headcount') {
                $this->assertFalse($value, 'a multi-value dimension never claims to reconcile to the headcount');
            }
        });
        $this->assertTrue($json['population']['duty_state']['reconciles_to_overall_headcount']);
        $this->assertTrue($json['demographics']['gender']['reconciles_to_overall_headcount']);
        $this->assertArrayNotHasKey('rows', $json);
        $this->assertNotContains('rows', array_keys($json['workforce_flows']));
    }

    public function test_a_zero_population_month_has_null_percentages_and_still_reports_its_events(): void
    {
        [$person, $rel] = $this->emp();
        $this->setStatus($person, $rel, 'retired', '2026-12-01');
        $this->viewer();

        $json = $this->getJson(self::URL.'?month=2026-12-01')->assertOk()->json();
        $empty = $this->getJson(self::URL.'?month=2020-01-01')->assertOk()->json();

        $this->assertSame(0, $empty['population']['overall_headcount']);
        foreach ($empty['population']['duty_state']['buckets'] as $bucket) {
            $this->assertNull($bucket['percentage']['basis_points'], 'null, never a fabricated 0%');
            $this->assertNull($bucket['percentage']['percent']);
        }
        $this->assertStringNotContainsString('NaN', json_encode($empty));
        $this->assertGreaterThanOrEqual(1, $json['workforce_flows']['relationship_ends']['event_only_event_count'], 'a month-start end is an event even outside the population');
    }

    // ------------------------------------------------------------------------------------------------------------
    // 2. S44-F1 (accepted debt): S44's duplicated R2/R4 rules must keep agreeing with R2/R4
    // ------------------------------------------------------------------------------------------------------------

    public function test_s44_terminal_events_and_reasons_agree_with_r4(): void
    {
        $this->mixedMonth();

        $r4 = app(BuildEmploymentStatusReportResult::class)(self::M);
        $s44 = app(BuildWorkforceAnalyticsResult::class)(self::M);

        $key = fn (array $e) => [$e['employment_relationship_id'], $e['event_date'], $e['last_employed_day'], $e['reason']['state'], $e['reason']['status_code'], $e['ended_terminally'], $e['relationship_in_monthly_population']];
        $r4Events = array_map($key, $r4->sections['relationship_terminal_events']['items']);
        $s44Events = array_map($key, $s44->terminalEvents);
        sort($r4Events);
        sort($s44Events);

        $this->assertSame($r4Events, $s44Events, 'S44 ends = R4 terminal events: same relationships, dates, reasons and event-only flag (S44-F1)');
        $this->assertContains(false, array_column($s44Events, 6), 'the month-start end is event-only in both');
        $this->assertSame($r4->sections['general_summary']['relationships_ended'], $s44->sections['workforce_flows']['relationship_ends']['event_count']);
        $this->assertSame($r4->sections['general_summary']['relationships_started'], $s44->sections['workforce_flows']['relationship_starts']['event_count']);
        $this->assertSame($r4->overallPersons, $s44->overallHeadcount, 'R4 and S44 share the complete S37 population');
        $this->assertSame($r4->overallPersons, $s44->sections['population']['overall_headcount'], 'the projected headcount is the S37 Person count, not a duty subset');
        $this->assertContains('NOT_RECORDED', array_column(array_column($s44->terminalEvents, 'reason'), 'state'), 'a non-ending status at the end date is NOT_RECORDED in both');
    }

    public function test_s44_status_exposure_agrees_with_r4_for_every_outcome(): void
    {
        $this->mixedMonth();

        $r4 = app(BuildEmploymentStatusReportResult::class)(self::M);
        $s44 = app(BuildWorkforceAnalyticsResult::class)(self::M);

        $r4Counts = array_column($r4->sections['status_exposure'], 'person_count', 'status');
        $s44Counts = array_column($s44->sections['employment_status']['status_exposure']['buckets'], 'person_count', 'bucket');
        ksort($r4Counts);
        ksort($s44Counts);

        $this->assertSame($r4Counts, $s44Counts, 'the same R4 outcome rule over the same S37 segments');
        $this->assertGreaterThan($s44->overallHeadcount, array_sum($s44Counts), 'exposure may exceed the headcount in both');
    }

    public function test_s44_actual_workplaces_agree_with_r2_for_the_r2_population(): void
    {
        $this->mixedMonth();

        $r2 = app(BuildAdministrativeReportResult::class)(self::M);
        $s44 = app(BuildWorkforceAnalyticsResult::class)(self::M);
        $s44ByPerson = [];
        foreach ($s44->rows as $row) {
            $s44ByPerson[$row->personId] = $row;
        }

        $this->assertNotEmpty($r2->rows);
        foreach ($r2->rows as $r2Row) {
            $s44Row = $s44ByPerson[$r2Row->personId];
            $this->assertSame('HAS_ON_DUTY', $s44Row->dutyClassification, 'R2 only reports HAS_ON_DUTY Persons');
            foreach ($r2Row->relationships as $i => $r2Rel) {
                $shape2 = array_map(fn (array $s) => [$s['from'], $s['to'], $s['state'], $s['organizational_unit_id'], $s['movement_type'], $s['underlying_of_partial_allocation'] ?? false, $s['movement_type'] === 'PARTIAL_SECONDMENT' ? $s['scheduled_weekdays_in_period'] : null], $r2Rel['actual_workplace_segments']);
                $shape44 = array_map(fn (array $s) => [$s['from'], $s['to'], $s['state'], $s['organizational_unit_id'], $s['movement_type'], $s['underlying_of_partial_allocation'] ?? false, $s['movement_type'] === 'PARTIAL_SECONDMENT' ? $s['weekdays'] : null], $s44Row->relationships[$i]['actual_workplace_segments']);
                $this->assertSame($shape2, $shape44, 'S44 actual-workplace occurrences = R2 occurrences (partial weekdays, underlying PLACEMENT, zero-weekday rule) — S44-F1');
            }
        }
    }

    // ------------------------------------------------------------------------------------------------------------
    // 3. INTENTIONAL non-reconciliation (DB-D63..D67): do not "fix" these
    // ------------------------------------------------------------------------------------------------------------

    public function test_the_headcount_intentionally_differs_from_r2_which_reports_only_has_on_duty_persons(): void
    {
        $this->mixedMonth();

        $r2 = app(BuildAdministrativeReportResult::class)(self::M);
        $s44 = app(BuildWorkforceAnalyticsResult::class)(self::M);
        $duty = array_column($s44->sections['population']['duty_state']['buckets'], 'person_count', 'duty_state');

        $this->assertGreaterThan($r2->overallHeadcount, $s44->overallHeadcount, 'S44 overall is the S37 population; R2 is its HAS_ON_DUTY subset');
        $this->assertSame($duty['HAS_ON_DUTY'], $r2->overallHeadcount, 'R2 headcount = S44 HAS_ON_DUTY');
        $this->assertSame($s44->overallHeadcount, $duty['HAS_ON_DUTY'] + $duty['NO_ON_DUTY'] + $duty['INDETERMINATE']);
    }

    public function test_relationship_type_is_intentionally_multi_value_unlike_r1_selected_relationship(): void
    {
        $person = $this->createPersonRecord();
        $a = $this->createEmploymentRelationship($person, 'permanent', null, '2026-01-01');
        $this->setStatus($person, $a, 'on_duty', '2026-10-01');
        $this->setStatus($person, $a, 'contract_ended', '2026-11-15');
        $this->createEmploymentRelationship($person, 'contract', null, '2026-11-15');

        $r1 = app(BuildHumanCadreResult::class)(self::M);
        $s44 = app(BuildWorkforceAnalyticsResult::class)(self::M);
        $r1Types = array_column($r1->summaries['employment_type'], 'count', 'code');
        $s44Types = array_column($s44->sections['employment']['relationship_type']['buckets'], 'person_count', 'bucket');

        $this->assertSame($r1->overallHeadcount, $s44->overallHeadcount, 'R1 and S44 share the S37 population');
        $this->assertSame($r1->overallHeadcount, array_sum($r1Types), 'R1 puts every Person in exactly ONE type (the selected relationship)');
        $this->assertGreaterThan($s44->overallHeadcount, array_sum($s44Types), 'S44 exposes a Person to every type of the month: the totals differ on purpose');
        $this->assertGreaterThanOrEqual(1, $s44Types['permanent'], 'the Person is exposed to permanent in S44 …');
        $this->assertSame(0, $r1Types['permanent'] ?? 0, '… while R1 classifies this Person only under contract');
    }

    // ------------------------------------------------------------------------------------------------------------
    // 4. Cross-stack contract pins
    // ------------------------------------------------------------------------------------------------------------

    public function test_the_frontend_kpi_contract_matches_the_backend_semantics_and_declares_no_unsupported_kpi(): void
    {
        $contract = (string) file_get_contents(base_path('../frontend/src/features/dashboard/contract.ts'));
        $this->assertNotSame('', $contract, 'the Dashboard KPI contract exists');

        foreach (['gender', 'primary_qualification'] as $kpi) {
            $this->assertMatchesRegularExpression("/id: '{$kpi}'[^}]*historical: 'CURRENT_RECORDED_ON_HISTORICAL_RERUN'/", $contract, "{$kpi} is CURRENT_RECORDED_ON_HISTORICAL_RERUN");
        }
        foreach (['specialty', 'employment_category', 'contract_dimension', 'organizational_placement', 'actual_workplace', 'status_exposure'] as $kpi) {
            $this->assertMatchesRegularExpression("/id: '{$kpi}'[^}]*historical: 'TEMPORAL_EXPOSURE'/", $contract, "{$kpi} is TEMPORAL_EXPOSURE");
        }
        foreach (['relationship_starts', 'relationship_ends'] as $kpi) {
            $this->assertMatchesRegularExpression("/id: '{$kpi}'[^}]*historical: 'EVENT_GRAIN'/", $contract, "{$kpi} is EVENT_GRAIN");
        }
        preg_match_all("/\\{ id: '([a-z_]+)'/", $contract, $ids);
        $this->assertCount(17, $ids[1]);
        foreach ($ids[1] as $id) {
            $this->assertDoesNotMatchRegularExpression('/absence|attendance|leave|vacanc|occupan|turnover|fte|productiv|payroll|forecast/', $id, "no unsupported KPI id: {$id}");
        }

        $this->viewer();
        $semantics = $this->getJson(self::URL.'?month='.self::M)->assertOk()->json('metadata.semantics');
        $this->assertSame('CURRENT_RECORDED', $semantics['gender']);
        $this->assertSame('CURRENT_RECORDED', $semantics['primary_qualification']);
        $this->assertSame('EVENT_GRAIN', $semantics['relationship_starts_and_ends']);
    }

    public function test_the_dashboard_permission_boundary_is_the_s44_one(): void
    {
        $this->viewer([Perm::MONTHLY_EMPLOYMENT_STATUS_REPORT_VIEW, Perm::MONTHLY_ADMINISTRATIVE_REPORT_VIEW, Perm::HUMAN_CADRE_VIEW]);
        $this->getJson(self::URL.'?month='.self::M)->assertStatus(403);

        $this->viewer();
        $this->getJson(self::URL.'?month='.self::M)->assertOk();
        $this->assertSame(0, (int) DB::table('security.role_permissions as rp')->join('security.permissions as p', 'p.id', '=', 'rp.permission_id')->join('security.roles as r', 'r.id', '=', 'rp.role_id')->where('p.code', Perm::WORKFORCE_ANALYTICS_VIEW)->where('r.is_system', true)->count(), 'no system or business role holds it by default');
    }
}
