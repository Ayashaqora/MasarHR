<?php

namespace Tests\Feature\HumanResources;

use App\Modules\HumanResources\Application\Commands\DesignateQualificationAsPrimary;
use App\Modules\HumanResources\Application\Commands\EndEmploymentRelationship;
use App\Modules\HumanResources\Application\Commands\RecordEmploymentSpecialtyPeriod;
use App\Modules\HumanResources\Application\Commands\RecordEmploymentStatusPeriod;
use App\Modules\HumanResources\Application\Commands\RecordPartialSecondmentPeriod;
use App\Modules\HumanResources\Application\Commands\RecordPersonQualification;
use App\Modules\HumanResources\Application\Commands\RecordWorkSchedulePeriod;
use App\Modules\HumanResources\Application\Commands\StartFullSecondment;
use App\Modules\HumanResources\Application\Commands\StartWorkplaceAssignment;
use App\Modules\HumanResources\Application\Queries\Reporting\BuildWorkforceAnalyticsResult;
use App\Modules\HumanResources\Application\Queries\Reporting\HumanCadreResult;
use App\Modules\HumanResources\Application\Queries\Reporting\ListMonthlyReportingPopulation;
use App\Modules\HumanResources\Application\Queries\Reporting\ListMonthlyWorkforceDimensions;
use App\Modules\HumanResources\Application\Queries\Reporting\WorkforceAnalyticsPersonRecord;
use App\Modules\HumanResources\Application\Queries\Reporting\WorkforceAnalyticsResult;
use App\Modules\HumanResources\Application\Queries\Reporting\WorkforceAnalyticsSections;
use App\Modules\HumanResources\Domain\CompletedAge;
use App\Modules\HumanResources\Infrastructure\Authorization\HumanResourcesPermissionCatalog as Perm;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\EmploymentRelationship;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\Person;
use App\Modules\Reference\Application\Commands\DefineSpecialtyCadreCategoryMappingPeriod;
use App\Modules\Reference\Infrastructure\Persistence\Eloquent\MonthlyCadreCategory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/**
 * S44 Workforce Analytics Foundation (docs/workforce-analytics-foundation-specification.md): analytics over the ONE canonical S37 population
 * (S40 enrichment of the precomputed population, existing pure Domain rules, S44-owned set-based batches). Real PostgreSQL, synthetic data only.
 * Report month: November 2026 [2026-11-01, 2026-12-01), the age reference / month end 2026-11-30. Assertions about fixtures target the
 * Persons this test creates; the reconciliation checks hold for the WHOLE result.
 */
class WorkforceAnalyticsFoundationTest extends HumanResourcesTestCase
{
    private const M = '2026-11-01';

    private const N = '2026-12-01';

    private const URL = '/api/v1/hr/workforce-analytics';

    private const BUILDER = 'app/Modules/HumanResources/Application/Queries/Reporting/BuildWorkforceAnalyticsResult.php';

    // ------------------------------------------------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------------------------------------------------

    private function analytics(string $month = self::M): WorkforceAnalyticsResult
    {
        return app(BuildWorkforceAnalyticsResult::class)($month);
    }

    /** A relationship with an explicit on_duty status from 2026-10-01 (the S06 behavior epoch is 2026-09-26). */
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

    private function rawStatus(EmploymentRelationship $rel, string $code, string $from, ?string $to = null, ?string $pay = null): void
    {
        DB::table('hr.employment_status_periods')->insert([
            'id' => (string) Str::uuid7(), 'employment_relationship_id' => $rel->id, 'status_detail_id' => $this->statusDetail($code)->id,
            'effective_from' => $from, 'effective_to' => $to, 'travel_pay_status' => $pay, 'created_at' => now(),
        ]);
    }

    private function end(Person $person, EmploymentRelationship $rel, string $to, bool $terminal = false): void
    {
        $rel->refresh();
        app(EndEmploymentRelationship::class)->handle($person, $rel, $rel->version, $to, $terminal);
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

    private function raw(string $stream, EmploymentRelationship $rel, string $valueId, string $from, ?string $to): void
    {
        [$table, $column] = [
            'category' => ['hr.employment_category_periods', 'employment_category_id'],
            'contract' => ['hr.employment_contract_periods', 'contract_type_id'],
        ][$stream];
        $row = ['id' => (string) Str::uuid7(), 'employment_relationship_id' => $rel->id, $column => $valueId, 'effective_from' => $from, 'effective_to' => $to, 'created_at' => now()];
        if ($stream === 'contract') {
            $row['contractual_effective_to'] = $to;
        }
        DB::table($table)->insert($row);
    }

    private function recordOrNull(Person $person, string $month = self::M): ?WorkforceAnalyticsPersonRecord
    {
        foreach ($this->analytics($month)->rows as $row) {
            if ($row->personId === $person->id) {
                return $row;
            }
        }

        return null;
    }

    private function record(Person $person, string $month = self::M): WorkforceAnalyticsPersonRecord
    {
        $record = $this->recordOrNull($person, $month);
        $this->assertNotNull($record, 'the Person is in the S37 population');

        return $record;
    }

    private function rel(Person $person, int $i = 0): array
    {
        return $this->record($person)->relationships[$i];
    }

    /** @return array<string, mixed> the bucket of a list whose $field equals $value */
    private function bucket(array $list, string $field, ?string $value): array
    {
        foreach ($list as $b) {
            if (($b[$field] ?? null) === $value) {
                return $b;
            }
        }
        $this->fail("bucket {$field}={$value} not found");
    }

    private function bucketCount(array $list, string $field, ?string $value, string $count = 'person_count'): int
    {
        return $this->bucket($list, $field, $value)[$count];
    }

    private function dq(WorkforceAnalyticsResult $r, string $code): array
    {
        foreach ($r->sections['data_quality'] as $entry) {
            if ($entry['code'] === $code) {
                return $entry;
            }
        }
        $this->fail("data-quality code {$code} missing");
    }

    private function statuses(callable $fn): array
    {
        $statements = [];
        DB::listen(function ($query) use (&$statements): void {
            $statements[] = $query->sql;
        });
        $fn();

        return $statements;
    }

    private function person(Person $person, array $attributes): void
    {
        DB::table('hr.persons')->where('id', $person->id)->update($attributes);
    }

    private function viewer(array $permissions = [Perm::WORKFORCE_ANALYTICS_VIEW]): void
    {
        $principal = $this->createPrincipal();
        $this->assignRole($principal, $this->createRoleWithPermissions($permissions));
        $this->actingAs($principal, 'web');
    }

    // ------------------------------------------------------------------------------------------------------------
    // 1. Population and duty
    // ------------------------------------------------------------------------------------------------------------

    public function test_an_empty_month_yields_zero_everywhere_and_null_percentages(): void
    {
        $r = $this->analytics('2020-01-01');

        $this->assertSame(0, $r->overallHeadcount);
        $this->assertSame([], $r->rows);
        $this->assertSame(0, $r->sections['population']['overall_headcount']);
        $this->assertSame(0, $r->sections['population']['relationship_count']);
        foreach ($r->sections['population']['duty_state']['buckets'] as $b) {
            $this->assertSame(0, $b['person_count']);
            $this->assertNull($b['percentage']['basis_points'], 'a zero denominator is never divided');
            $this->assertNull($b['percentage']['percent']);
            $this->assertSame(0, $b['percentage']['denominator_value']);
        }
        $this->assertTrue($r->sections['population']['duty_state']['reconciles_to_overall_headcount']);
        $this->assertSame(0, $r->sections['workforce_flows']['relationship_starts']['event_count']);
        $this->assertSame(0, $r->sections['workforce_flows']['relationship_ends']['event_count']);
        foreach ($r->sections['data_quality'] as $entry) {
            $this->assertSame(0, $entry['person_count']);
        }
        $this->assertSame(WorkforceAnalyticsResult::STATUS_OUTCOMES, array_column($r->sections['employment_status']['status_exposure']['buckets'], 'bucket'));
    }

    public function test_one_person_and_the_duty_classifications_reconcile_to_the_overall_headcount(): void
    {
        [$on] = $this->emp();
        [$captive, $captiveRel] = $this->emp(onDuty: false);
        $this->rawStatus($captiveRel, 'captive', '2026-10-05');
        [$none] = $this->emp(onDuty: false);

        $r = $this->analytics();
        $duty = $r->sections['population']['duty_state'];

        $this->assertSame('HAS_ON_DUTY', $this->record($on)->dutyClassification);
        $this->assertSame('NO_ON_DUTY', $this->record($captive)->dutyClassification);
        $this->assertSame('INDETERMINATE', $this->record($none)->dutyClassification);
        $this->assertSame($r->overallHeadcount, array_sum(array_column($duty['buckets'], 'person_count')), 'OVERALL = HAS + NO + INDETERMINATE');
        $this->assertTrue($duty['reconciles_to_overall_headcount']);
        $this->assertSame(['HAS_ON_DUTY', 'NO_ON_DUTY', 'INDETERMINATE'], array_column($duty['buckets'], 'duty_state'));
        $this->assertGreaterThanOrEqual(1, $this->bucketCount($duty['buckets'], 'duty_state', 'NO_ON_DUTY'));
        foreach (['HAS_ON_DUTY', 'NO_ON_DUTY', 'INDETERMINATE'] as $classification) {
            $this->assertSame(count(array_filter($r->rows, fn ($row) => $row->dutyClassification === $classification)), $this->bucketCount($duty['buckets'], 'duty_state', $classification), "{$classification} is exactly the S37 classification, never reinterpreted");
        }
        $this->assertSame(count($r->rows), $r->overallHeadcount);
        $this->assertSame(count($r->rows), $r->sections['population']['overall_headcount']);
        $this->assertSame(
            array_map(fn ($p) => $p->personId, app(ListMonthlyReportingPopulation::class)(self::M)->persons),
            array_map(fn ($row) => $row->personId, $r->rows),
            'exactly the S37 population, in the S37 order',
        );
    }

    public function test_a_person_with_two_relationships_counts_once_and_the_relationship_count_is_independent(): void
    {
        $person = $this->createPersonRecord();
        $a = $this->createEmploymentRelationship($person, 'permanent', null, '2026-01-01');
        $this->setStatus($person, $a, 'on_duty', '2026-10-01');
        $this->setStatus($person, $a, 'resigned', '2026-11-10');
        $b = $this->createEmploymentRelationship($person, 'contract', null, '2026-11-10');
        $before = $this->analytics();

        $this->assertCount(1, array_filter($before->rows, fn ($row) => $row->personId === $person->id), 'one record per Person');
        $this->assertCount(2, $this->record($person)->relationships);
        $this->assertSame([$a->id, $b->id], array_column($this->record($person)->relationships, 'employment_relationship_id'));
        $this->assertSame(count($before->rows), $before->sections['population']['overall_headcount']);
        $this->assertSame(
            array_sum(array_map(fn ($row) => count($row->relationships), $before->rows)),
            $before->sections['population']['relationship_count'],
        );
        $this->assertGreaterThan($before->overallHeadcount, $before->sections['population']['relationship_count'], 'relationships exceed Persons when a Person has two');
    }

    // ------------------------------------------------------------------------------------------------------------
    // 2. Single-value dimensions
    // ------------------------------------------------------------------------------------------------------------

    public function test_gender_is_single_valued_with_not_recorded_and_reconciles(): void
    {
        [$male] = $this->emp();
        [$female] = $this->emp();
        $this->person($female, ['gender_id' => $this->gender('female')->id]);
        [$missing] = $this->emp();
        $this->person($missing, ['gender_id' => null]);

        $r = $this->analytics();
        $gender = $r->sections['demographics']['gender'];

        $this->assertSame('MALE', $this->record($male)->gender['code']);
        $this->assertSame('FEMALE', $this->record($female)->gender['code']);
        $this->assertSame('NOT_RECORDED', $this->record($missing)->gender['state']);
        $this->assertGreaterThanOrEqual(1, $this->bucketCount($gender['buckets'], 'gender', 'MALE'));
        $this->assertGreaterThanOrEqual(1, $this->bucketCount($gender['buckets'], 'gender', 'FEMALE'));
        $this->assertGreaterThanOrEqual(1, $this->bucketCount($gender['buckets'], 'gender', 'NOT_RECORDED'));
        $this->assertSame('NOT_RECORDED', end($gender['buckets'])['gender'], 'the missing state is listed last and is never merged into another bucket');
        $this->assertSame($r->overallHeadcount, array_sum(array_column($gender['buckets'], 'person_count')));
        $this->assertTrue($gender['reconciles_to_overall_headcount']);
        $this->assertSame('CURRENT_RECORDED', $gender['basis']);
        $this->assertContains($missing->id, $this->dq($r, 'GENDER_NOT_RECORDED')['affected_person_ids']);
        $this->assertNotContains($male->id, $this->dq($r, 'GENDER_NOT_RECORDED')['affected_person_ids']);
    }

    public function test_age_uses_the_completed_age_rules_at_the_last_day_of_the_month(): void
    {
        $birth = fn (string $date) => tap($this->emp()[0], fn (Person $p) => $this->person($p, ['birth_date' => $date]));
        $day24 = $birth('2001-11-30'); // turns 25 on the reference date: completed 25 -> 25-34
        $before25 = $birth('2001-12-01'); // one day short: 24 -> <25
        $leap = $birth('2000-02-29'); // Feb-29 anniversary: 26 on 2026-02-28 → at 11-30 completed 26
        $none = tap($this->emp()[0], fn (Person $p) => $this->person($p, ['birth_date' => null]));
        $future = $birth('2026-12-15'); // after the report date: state NOT_CALCULABLE, band NOT_RECORDED (WA-D69)

        $r = $this->analytics();
        $age = $r->sections['demographics']['age'];

        $this->assertSame([25, '25-34'], [$this->record($day24)->age['years'], $this->record($day24)->age['band']]);
        $this->assertSame([24, '<25'], [$this->record($before25)->age['years'], $this->record($before25)->age['band']]);
        $this->assertSame(26, $this->record($leap)->age['years']);
        $this->assertSame('NOT_RECORDED', $this->record($none)->age['state']);
        $this->assertSame('NOT_CALCULABLE', $this->record($future)->age['state']);
        $this->assertSame(CompletedAge::BANDS, array_column($age['buckets'], 'band'), 'the official bands are exactly the frozen R1 bands');
        $this->assertSame(['<25', '25-34', '35-44', '45-54', '55-64', '65+', 'NOT_RECORDED'], array_column($age['buckets'], 'band'));
        $this->assertNotContains('NOT_CALCULABLE', array_column($age['buckets'], 'band'), 'NOT_CALCULABLE is NOT an official age band');
        $this->assertSame(['NOT_CALCULABLE', 'NOT_RECORDED'], [$this->record($future)->age['state'], $this->record($future)->age['band']], 'calculation state NOT_CALCULABLE, band NOT_RECORDED');
        $this->assertGreaterThanOrEqual(2, $this->bucketCount($age['buckets'], 'band', 'NOT_RECORDED'), 'no birth date AND a future birth date both land in the NOT_RECORDED band');
        $states = array_column($age['calculation_states'], 'person_count', 'state');
        $this->assertSame(['CALCULABLE', 'NOT_RECORDED', 'NOT_CALCULABLE'], array_column($age['calculation_states'], 'state'), 'the value state stays distinct from the band');
        $this->assertGreaterThanOrEqual(1, $states['NOT_CALCULABLE']);
        $this->assertSame($r->overallHeadcount, array_sum($states));
        $this->assertSame($this->bucketCount($age['buckets'], 'band', 'NOT_RECORDED'), $states['NOT_RECORDED'] + $states['NOT_CALCULABLE'], 'band NOT_RECORDED = value state NOT_RECORDED + NOT_CALCULABLE');
        $this->assertSame($r->overallHeadcount, array_sum(array_column($age['buckets'], 'person_count')));
        $this->assertTrue($age['reconciles_to_overall_headcount']);
        $this->assertContains($future->id, $this->dq($r, HumanCadreResult::DQ_BIRTH_DATE_AFTER_REPORT_DATE)['affected_person_ids']);
        $this->assertNotContains($none->id, $this->dq($r, HumanCadreResult::DQ_BIRTH_DATE_AFTER_REPORT_DATE)['affected_person_ids']);
    }

    public function test_the_feb_29_anniversary_follows_the_completed_age_rule(): void
    {
        $leapling = $this->emp()[0];
        $this->person($leapling, ['birth_date' => '2000-02-29']);
        $marchBorn = $this->emp()[0];
        $this->person($marchBorn, ['birth_date' => '2000-03-01']);

        // Reference date 2027-02-28 (a non-leap year): the Feb-29 anniversary falls on Feb 28; a March-1 birthday has not happened yet.
        $this->assertSame(27, $this->record($leapling, '2027-02-01')->age['years']);
        $this->assertSame(26, $this->record($marchBorn, '2027-02-01')->age['years']);
        // Reference date 2028-02-29 (a leap year): the leapling's real birthday.
        $this->assertSame(28, $this->record($leapling, '2028-02-01')->age['years']);
        $this->assertSame(CompletedAge::at('2000-02-29', '2027-02-28')['years'], $this->record($leapling, '2027-02-01')->age['years'], 'the existing R1 calculator, not a copy');
    }

    public function test_age_band_boundaries_are_the_r1_bands(): void
    {
        $expected = ['2001-12-02' => [24, '<25'], '2001-11-30' => [25, '25-34'], '1992-11-30' => [34, '25-34'], '1991-11-30' => [35, '35-44'], '1982-11-30' => [44, '35-44'],
            '1981-11-30' => [45, '45-54'], '1972-11-30' => [54, '45-54'], '1971-11-30' => [55, '55-64'], '1962-11-30' => [64, '55-64'], '1961-11-30' => [65, '65+']];
        $people = [];
        foreach ($expected as $birth => $shape) {
            $person = $this->emp()[0];
            $this->person($person, ['birth_date' => $birth]);
            $people[$birth] = $person;
        }

        foreach ($expected as $birth => [$years, $band]) {
            $this->assertSame([$years, $band], [$this->record($people[$birth])->age['years'], $this->record($people[$birth])->age['band']], $birth);
        }
    }

    public function test_primary_qualification_is_the_current_primary_never_guessed(): void
    {
        [$withPrimary] = $this->emp();
        $first = app(RecordPersonQualification::class)->handle($withPrimary, $this->createSyntheticAcademicDegree(), null, null, $this->syntheticActorPrincipalId());
        [$none] = $this->emp();
        [$twoNoPrimary] = $this->emp();
        app(RecordPersonQualification::class)->handle($twoNoPrimary, $this->createSyntheticAcademicDegree(), null, null, $this->syntheticActorPrincipalId());
        app(RecordPersonQualification::class)->handle($twoNoPrimary, $this->createSyntheticAcademicDegree(), null, null, $this->syntheticActorPrincipalId());
        DB::table('hr.person_qualifications')->where('person_id', $twoNoPrimary->id)->update(['is_primary' => false]);
        [$designated] = $this->emp();
        app(RecordPersonQualification::class)->handle($designated, $this->createSyntheticAcademicDegree(), null, null, $this->syntheticActorPrincipalId());
        $second = app(RecordPersonQualification::class)->handle($designated, $this->createSyntheticAcademicDegree(), null, null, $this->syntheticActorPrincipalId());
        app(DesignateQualificationAsPrimary::class)->handle($designated, $second->qualification);

        $r = $this->analytics();
        $section = $r->sections['qualifications']['primary_qualification'];

        $this->assertSame('PRIMARY', $this->record($withPrimary)->primaryQualification['state']);
        $this->assertSame('NOT_RECORDED', $this->record($none)->primaryQualification['state']);
        $this->assertSame('NOT_RECORDED', $this->record($twoNoPrimary)->primaryQualification['state'], 'several qualifications without a Primary: no latest/highest guess');
        $this->assertContains($twoNoPrimary->id, $this->dq($r, HumanCadreResult::DQ_PRIMARY_QUALIFICATION_REQUIRED)['affected_person_ids']);
        $this->assertNotContains($none->id, $this->dq($r, HumanCadreResult::DQ_PRIMARY_QUALIFICATION_REQUIRED)['affected_person_ids'], 'no qualification at all is not "required"');
        $this->assertSame($second->version->academic_degree_id, $this->record($designated)->primaryQualification['academic_degree']['id'], 'the designated Primary, not the first');
        $this->assertSame($first->version->academic_degree_id, $this->record($withPrimary)->primaryQualification['academic_degree']['id'], 'the first qualification is the application-level auto Primary');
        $this->assertSame($r->overallHeadcount, array_sum(array_column($section['buckets'], 'person_count')));
        $this->assertTrue($section['reconciles_to_overall_headcount']);
    }

    public function test_service_is_person_cumulative_and_uses_the_r1_calculator(): void
    {
        [$normal] = $this->emp('2026-01-01');
        $exact5 = $this->emp('2021-12-02')[0]; // 2021-12-02 .. 2026-12-01 = 1825 days = 5 completed years
        $short5 = $this->emp('2021-12-03')[0]; // 1824 days = 4 years
        $gap = $this->createPersonRecord();
        $g1 = $this->createEmploymentRelationship($gap, 'permanent', null, '2020-01-01');
        $this->end($gap, $g1, '2021-01-01');
        $this->createEmploymentRelationship($gap, 'contract', null, '2022-01-01');
        [$unpaid, $u] = $this->emp('2026-01-01', onDuty: false);
        $this->rawStatus($u, 'unpaid_leave', '2026-03-01', '2026-04-01');

        $r = $this->analytics();

        $this->assertSame([334, '<5'], [$this->record($normal)->service['service_days'], $this->record($normal)->service['band']]);
        $this->assertSame([1825, 5, '5-9'], [$this->record($exact5)->service['service_days'], $this->record($exact5)->service['completed_service_years'], $this->record($exact5)->service['band']]);
        $this->assertSame([1824, '<5'], [$this->record($short5)->service['service_days'], $this->record($short5)->service['band']]);
        $this->assertSame(2161, $this->record($gap)->service['service_days'], '366 + 1795 days; the 2021 gap is not counted');
        $this->assertSame(303, $this->record($unpaid)->service['service_days'], 'a recorded unpaid leave does not count (334 - 31)');
        $this->assertSame($r->overallHeadcount, array_sum(array_column($r->sections['employment']['service']['buckets'], 'person_count')));
        $this->assertTrue($r->sections['employment']['service']['reconciles_to_overall_headcount']);
        $this->assertSame(['<5', '5-9', '10-14', '15-19', '20-24', '25-29', '30+', 'INCOMPLETE'], array_column($r->sections['employment']['service']['buckets'], 'band'));
    }

    public function test_service_status_rules_and_unknown_legacy_follow_the_calculator(): void
    {
        [$paid, $a] = $this->emp('2026-01-01', onDuty: false);
        $this->rawStatus($a, 'traveling', '2026-03-01', '2026-04-01', 'PAID');
        [$unpaidTravel, $b] = $this->emp('2026-01-01', onDuty: false);
        $this->rawStatus($b, 'traveling', '2026-03-01', '2026-04-01', 'UNPAID');
        [$nullTravel, $c] = $this->emp('2026-01-01', onDuty: false);
        $this->rawStatus($c, 'traveling', '2026-03-01', '2026-04-01');
        [$captive, $d] = $this->emp('2026-01-01', onDuty: false);
        $this->rawStatus($d, 'captive', '2026-03-01');
        [$sick, $e] = $this->emp('2026-01-01', onDuty: false);
        $this->rawStatus($e, 'external_sick_leave', '2026-03-01', '2026-07-01'); // a 122-day run: only the first 90 days count
        $legacy = $this->createPersonRecord();
        $this->insertLegacy($legacy, '2020-01-01');

        $r = $this->analytics();

        $this->assertSame(334, $this->record($paid)->service['service_days'], 'PAID traveling counts');
        $this->assertSame(303, $this->record($unpaidTravel)->service['service_days'], 'UNPAID traveling does not');
        $this->assertSame(334, $this->record($nullTravel)->service['service_days']);
        $this->assertContains($nullTravel->id, $this->dq($r, HumanCadreResult::DQ_TRAVEL_PAY_STATUS_NOT_RECORDED)['affected_person_ids']);
        $this->assertNotContains($paid->id, $this->dq($r, HumanCadreResult::DQ_TRAVEL_PAY_STATUS_NOT_RECORDED)['affected_person_ids']);
        $this->assertSame(334, $this->record($captive)->service['service_days']);
        $this->assertSame(334 - (122 - 90), $this->record($sick)->service['service_days'], 'the continuous external-sick-leave run counts 90 days');
        $this->assertSame('INCOMPLETE', $this->record($legacy)->service['state'], 'an UNKNOWN_LEGACY end makes service INCOMPLETE');
        $this->assertNull($this->record($legacy)->service['service_days']);
        $this->assertSame('INCOMPLETE', $this->record($legacy)->service['band']);
        $this->assertContains($legacy->id, $this->dq($r, 'UNKNOWN_LEGACY_RELATIONSHIP_END')['affected_person_ids']);
        $this->assertGreaterThanOrEqual(1, $this->bucketCount($r->sections['employment']['service']['buckets'], 'band', 'INCOMPLETE'));
    }

    public function test_service_covers_every_relationship_not_one_selected_relationship(): void
    {
        $person = $this->createPersonRecord();
        $a = $this->createEmploymentRelationship($person, 'permanent', null, '2020-01-01');
        $this->end($person, $a, '2025-01-01'); // 1827 days
        $this->createEmploymentRelationship($person, 'contract', null, '2026-11-01'); // 30 days in the month
        $service = $this->record($person)->service;

        $this->assertSame(1827 + 30, $service['service_days'], 'the earlier relationship counts too (R1\'s latest-relationship selection is NOT used)');
        $this->assertSame(['5-9', 5], [$service['band'], $service['completed_service_years']]);
    }

    // ------------------------------------------------------------------------------------------------------------
    // 3. Multi-value dimensions
    // ------------------------------------------------------------------------------------------------------------

    public function test_relationship_type_is_a_multi_value_exposure_dimension(): void
    {
        $person = $this->createPersonRecord();
        $a = $this->createEmploymentRelationship($person, 'permanent', null, '2026-01-01');
        $this->setStatus($person, $a, 'on_duty', '2026-10-01');
        $this->setStatus($person, $a, 'contract_ended', '2026-11-15');
        $this->createEmploymentRelationship($person, 'contract', null, '2026-11-15');
        [$only] = $this->emp('2026-01-01', 'permanent');

        $r = $this->analytics();
        $types = $r->sections['employment']['relationship_type'];

        $this->assertFalse($types['buckets_reconcile_to_overall_headcount']);
        $this->assertSame('MULTI_VALUE_TEMPORAL', $types['grain']);
        $permanent = $this->bucket($types['buckets'], 'relationship_type', 'permanent');
        $contract = $this->bucket($types['buckets'], 'relationship_type', 'contract');
        $this->assertGreaterThanOrEqual(2, $permanent['person_count']);
        $this->assertGreaterThanOrEqual(1, $contract['person_count']);
        $this->assertSame(['permanent', 'contract'], array_map(fn ($rel) => $rel['employment_type']['code'], $this->record($person)->relationships), 'the Person is exposed to BOTH types');
        $this->assertGreaterThan($r->overallHeadcount, array_sum(array_column($types['buckets'], 'person_count')), 'the bucket totals may exceed the overall headcount');
        $this->assertSame(WorkforceAnalyticsResult::SHARE_EXPOSED_SEMANTICS, $permanent['exposure_share']['semantics']);
        $this->assertTrue(in_array($only->id, array_map(fn ($row) => $row->personId, $r->rows), true));
    }

    public function test_employment_category_contract_and_specialty_keep_their_temporal_segments_and_states(): void
    {
        [$person, $rel] = $this->emp('2026-01-01', 'contract');
        $catA = $this->createSyntheticEmploymentCategory();
        $catB = $this->createSyntheticEmploymentCategory();
        $this->raw('category', $rel, $catA->id, '2026-02-01', '2026-11-15');
        $this->raw('category', $rel, $catB->id, '2026-11-15', null);
        $contractA = $this->createSyntheticContractType();
        $this->raw('contract', $rel, $contractA->id, '2026-02-01', '2027-01-01');
        $spec = $this->createSyntheticSpecialty();
        app(RecordEmploymentSpecialtyPeriod::class)->handle($rel->refresh(), $spec, '2026-02-01');
        [$bare] = $this->emp('2026-01-01', 'contract');
        [$permanent] = $this->emp('2026-01-01', 'permanent');

        $r = $this->analytics();
        $categories = $r->sections['employment']['employment_category']['buckets'];

        $this->assertSame(['RESOLVED', 'RESOLVED'], array_column($this->rel($person)['category_segments'], 'state'));
        $this->assertSame([$catA->code, $catB->code], array_column($this->rel($person)['category_segments'], 'code'));
        $this->assertSame(1, $this->bucketCount($categories, 'bucket', $catA->code), 'one Person per category bucket (exposure, not segments)');
        $this->assertSame(1, $this->bucketCount($categories, 'bucket', $catB->code));
        $this->assertGreaterThanOrEqual(1, $this->bucketCount($categories, 'bucket', 'NOT_RECORDED'), 'a gap stays NOT_RECORDED, never Other');
        $this->assertNotContains('OTHER', array_column($categories, 'bucket'));
        $contracts = $r->sections['employment']['contract_dimension'];
        $this->assertSame(1, $this->bucketCount($contracts['contract_types']['buckets'], 'bucket', $contractA->code));
        $this->assertSame('NOT_APPLICABLE', $this->rel($permanent)['contract_segments'][0]['state']);
        $this->assertGreaterThanOrEqual(1, $this->bucketCount($contracts['contract_types']['buckets'], 'bucket', 'NOT_APPLICABLE'), 'a contract on a non-CONTRACT relationship is NOT_APPLICABLE');
        $this->assertGreaterThanOrEqual(1, $this->bucketCount($contracts['contract_types']['buckets'], 'bucket', 'NOT_RECORDED'));
        $this->assertSame('UNMAPPED', $this->rel($person)['contract_segments'][0]['population_mapping']['state']);
        $this->assertGreaterThanOrEqual(1, $this->bucketCount($contracts['population_mapping']['buckets'], 'bucket', 'UNMAPPED'), 'UNMAPPED is never converted into Other');
        $specialties = $r->sections['qualifications']['specialty'];
        $this->assertSame(1, $this->bucketCount($specialties['specialties']['buckets'], 'bucket', $spec->code));
        $this->assertGreaterThanOrEqual(1, $this->bucketCount($specialties['specialties']['buckets'], 'bucket', 'NOT_RECORDED'));
        $this->assertGreaterThanOrEqual(1, $this->bucketCount($specialties['cadre_mapping']['buckets'], 'bucket', 'UNMAPPED'));
        $this->assertSame('NOT_RECORDED', $this->rel($bare)['category_segments'][0]['state']);
    }

    public function test_a_resolved_cadre_mapping_is_exposed_by_its_target_code(): void
    {
        [$person, $rel] = $this->emp();
        $spec = $this->createSyntheticSpecialty();
        app(RecordEmploymentSpecialtyPeriod::class)->handle($rel->refresh(), $spec, '2026-02-01');
        $cadre = MonthlyCadreCategory::query()->firstOrFail();
        app(DefineSpecialtyCadreCategoryMappingPeriod::class)->handle($spec, $cadre, '2026-01-01', null);

        $this->assertSame(['RESOLVED', $cadre->code], [$this->rel($person)['specialty_segments'][0]['cadre_mapping']['state'], $this->rel($person)['specialty_segments'][0]['cadre_mapping']['code']]);
        $this->assertGreaterThanOrEqual(1, $this->bucketCount($this->analytics()->sections['qualifications']['specialty']['cadre_mapping']['buckets'], 'bucket', $cadre->code));
    }

    public function test_organizational_placement_keeps_transitions_and_is_independent_of_actual_workplace(): void
    {
        [$person, $rel] = $this->emp();
        $root = $this->createUnit('الجذر');
        $a = $this->createUnit('وحدة أ', $root->id);
        $b = $this->createUnit('وحدة ب', $root->id);
        $this->recordPlacement($rel, $a, '2026-02-01');
        $this->recordPlacement($rel, $b, '2026-11-15');
        [$other, $otherRel] = $this->emp();
        $this->recordPlacement($otherRel, $a, '2026-02-01');
        [$unplaced] = $this->emp();
        app(StartFullSecondment::class)->handle($otherRel->refresh(), $b, '2026-11-10');

        $r = $this->analytics();
        $units = $r->sections['organization']['organizational_placement']['units'];

        $this->assertSame([$a->id, $b->id], array_column($this->rel($person)['placement_segments'], 'organizational_unit_id'));
        $this->assertSame(2, $this->bucketCount($units, 'unit_id', $a->id, 'direct_person_count'), 'both Persons are placed at A');
        $this->assertSame(1, $this->bucketCount($units, 'unit_id', $b->id, 'direct_person_count'), 'the full secondment does NOT move the organizational placement');
        $this->assertSame(2, $this->bucketCount($units, 'unit_id', $root->id, 'subtree_person_count'), 'subtree = distinct Persons placed at the unit or any descendant');
        $this->assertSame(0, $this->bucketCount($units, 'unit_id', $root->id, 'direct_person_count'));
        $this->assertSame([0, 1], [$this->bucket($units, 'unit_id', $root->id)['depth'], $this->bucket($units, 'unit_id', $a->id)['depth']]);
        $this->assertSame([$root->id, $a->id], $this->bucket($units, 'unit_id', $a->id)['path']);
        $this->assertSame('RESOLVED', $this->rel($other)['actual_workplace_segments'][count($this->rel($other)['actual_workplace_segments']) - 1]['state']);
        $this->assertGreaterThanOrEqual(1, $r->sections['organization']['organizational_placement']['not_recorded_person_count']);
        $this->assertContains($unplaced->id, $this->dq($r, 'ORGANIZATIONAL_PLACEMENT_NOT_RECORDED')['affected_person_ids']);
        $this->assertSame(WorkforceAnalyticsResult::SHARE_EXPOSED_SEMANTICS, $r->sections['organization']['organizational_placement']['semantics']);
    }

    public function test_actual_workplace_keeps_every_movement_type_weekdays_as_allocation_and_the_underlying_placement(): void
    {
        [$full, $fullRel] = $this->emp();
        [$assigned, $assignedRel] = $this->emp();
        [$partial, $partialRel] = $this->emp();
        [$zero, $zeroRel] = $this->emp();
        $home = $this->createUnit('الأصل');
        $dFull = $this->createUnit('وجهة إعارة');
        $dAssign = $this->createUnit('وجهة تكليف');
        $dPartial = $this->createUnit('وجهة جزئية');
        $dZero = $this->createUnit('وجهة بلا أيام');
        foreach ([$fullRel, $assignedRel, $partialRel, $zeroRel] as $rel) {
            $this->recordPlacement($rel, $home, '2026-02-01');
        }
        app(StartFullSecondment::class)->handle($fullRel->refresh(), $dFull, '2026-11-10');
        app(StartWorkplaceAssignment::class)->handle($assignedRel->refresh(), $dAssign, '2026-11-12', $this->assignmentDecisionType());
        app(RecordWorkSchedulePeriod::class)->handle($partialRel, '2026-01-01', ['SUNDAY', 'MONDAY', 'TUESDAY', 'WEDNESDAY', 'THURSDAY']);
        app(RecordPartialSecondmentPeriod::class)->handle($partialRel->refresh(), $dPartial, '2026-11-10', '2026-11-24', ['SUNDAY', 'TUESDAY']);
        app(RecordWorkSchedulePeriod::class)->handle($zeroRel, '2026-01-01', ['SUNDAY', 'MONDAY', 'TUESDAY', 'WEDNESDAY', 'THURSDAY']);
        app(RecordPartialSecondmentPeriod::class)->handle($zeroRel->refresh(), $dZero, '2026-11-10', '2026-11-12', ['SUNDAY']);

        $r = $this->analytics();
        $workplaces = $r->sections['actual_work']['actual_workplaces']['workplaces'];
        $partialUnit = $this->bucket($workplaces, 'unit_id', $dPartial->id);
        $homeUnit = $this->bucket($workplaces, 'unit_id', $home->id);

        $this->assertSame(['PLACEMENT', 'FULL_SECONDMENT'], array_column($this->rel($full)['actual_workplace_segments'], 'movement_type'), 'one Person, two workplaces in the month');
        $this->assertSame(1, $this->bucketCount($workplaces, 'unit_id', $dFull->id));
        $this->assertSame(1, $this->bucketCount($workplaces, 'unit_id', $dAssign->id));
        $this->assertSame('WORKPLACE_ASSIGNMENT', $this->bucket($workplaces, 'unit_id', $dAssign->id)['movement_types'][0]['movement_type']);
        $this->assertSame([['movement_type' => 'PARTIAL_SECONDMENT', 'person_count' => 1]], $partialUnit['movement_types']);
        $this->assertSame(['TUESDAY', 'SUNDAY'], array_column($partialUnit['allocated_weekdays'], 'weekday'), 'the configured weekdays are the only explicit allocations (ISO order)');
        $this->assertSame([1, 1], array_column($partialUnit['allocated_weekdays'], 'person_count'));
        $this->assertTrue($r->sections['actual_work']['actual_workplaces']['allocation_is_not_attendance']);
        $this->assertContains('PLACEMENT_UNDERLYING_OF_PARTIAL_ALLOCATION', array_column($homeUnit['movement_types'], 'movement_type'), 'the base placement stays the distinguishable underlying PLACEMENT');
        $this->assertNotContains($dZero->id, array_column($workplaces, 'unit_id'), 'zero applicable weekdays: no destination occurrence');
        $this->assertContains($home->id, array_column($workplaces, 'unit_id'));
        $this->assertGreaterThanOrEqual(1, $this->bucketCount($workplaces, 'unit_id', $home->id), 'the organizational placement is untouched');
        $json = strtolower(json_encode($r->sections['actual_work']));
        foreach (['attendance_days', 'worked_days', 'fte', 'residual'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $json, "weekdays are allocation only: no {$forbidden}");
        }
    }

    public function test_an_unresolved_workplace_stays_non_determinable_and_a_data_quality_finding(): void
    {
        [$person, $rel] = $this->emp();
        $a = $this->createUnit('أ');
        $b = $this->createUnit('ب');
        $this->recordPlacement($rel, $a, '2026-02-01');
        DB::table('hr.full_secondment_periods')->insert(['id' => (string) Str::uuid7(), 'employment_relationship_id' => $rel->id, 'organizational_unit_id' => $b->id, 'effective_from' => '2026-11-10', 'effective_to' => null, 'created_at' => now()]);
        DB::table('hr.workplace_assignment_periods')->insert(['id' => (string) Str::uuid7(), 'employment_relationship_id' => $rel->id, 'organizational_unit_id' => $b->id, 'effective_from' => '2026-11-10', 'effective_to' => null, 'created_at' => now()]);

        $r = $this->analytics();

        $this->assertContains($person->id, $this->dq($r, 'ACTUAL_WORKPLACE_NOT_DETERMINABLE')['affected_person_ids']);
        $this->assertNotEmpty($r->sections['actual_work']['actual_workplaces']['non_determinable']);
        $this->assertSame(['AMBIGUOUS_MOVEMENT_STATE'], array_column($r->sections['actual_work']['actual_workplaces']['non_determinable'], 'state'));
    }

    // ------------------------------------------------------------------------------------------------------------
    // 4. Employment status exposure and workforce flows
    // ------------------------------------------------------------------------------------------------------------

    public function test_status_exposure_counts_distinct_persons_and_may_exceed_the_overall_headcount(): void
    {
        [$person, $rel] = $this->emp();
        $this->setStatus($person, $rel, 'traveling', '2026-11-02', '2026-11-05');
        $this->setStatus($person, $rel, 'suspended', '2026-11-10', '2026-11-15');
        $this->setStatus($person, $rel, 'traveling', '2026-11-20', '2026-11-25');
        [$captive, $c] = $this->emp();
        $this->setStatus($captive, $c, 'captive', '2026-11-10');
        [$unpaid, $u] = $this->emp();
        $this->setStatus($unpaid, $u, 'unpaid_leave', '2026-11-10', '2026-11-18');
        [$sick, $s] = $this->emp();
        $this->setStatus($sick, $s, 'external_sick_leave', '2026-11-10', '2026-11-18');
        [$none] = $this->emp(onDuty: false);

        $r = $this->analytics();
        $exposure = $r->sections['employment_status']['status_exposure'];
        $in = fn (Person $p, string $status) => in_array($p->id, array_map(fn ($row) => $row->personId, array_filter($r->rows, fn ($row) => in_array($status, array_merge(...array_map(fn ($rel) => array_column($rel['status_segments'], 'outcome'), $row->relationships)), true))), true);

        foreach (['ON_DUTY', 'TRAVELING', 'SUSPENDED'] as $status) {
            $this->assertTrue($in($person, $status), "the Person is exposed to {$status}");
        }
        $this->assertTrue($in($captive, 'CAPTIVE'));
        $this->assertTrue($in($unpaid, 'UNPAID_LEAVE'));
        $this->assertTrue($in($sick, 'EXTERNAL_SICK_LEAVE'));
        $this->assertTrue($in($none, 'INDETERMINATE'));
        $this->assertSame(['ON_DUTY', 'TRAVELING', 'CAPTIVE', 'SUSPENDED', 'UNPAID_LEAVE', 'EXTERNAL_SICK_LEAVE', 'INDETERMINATE'], array_column($exposure['buckets'], 'bucket'));
        $this->assertFalse($exposure['buckets_reconcile_to_overall_headcount']);
        $this->assertGreaterThan($r->overallHeadcount, array_sum(array_column($exposure['buckets'], 'person_count')), 'a Person in several buckets: the sum exceeds the headcount');
        $traveling = $this->bucket($exposure['buckets'], 'bucket', 'TRAVELING');
        $this->assertSame(1, $traveling['person_count'], 'two TRAVELING periods are ONE Person exposure');
        $this->assertSame(1, $traveling['relationship_count']);
        $this->assertLessThanOrEqual($r->overallHeadcount, $traveling['person_count']);
        $this->assertContains('INDETERMINATE_STATUS_COVERAGE', $this->rel($none)['data_quality']);
        $this->assertContains($none->id, $this->dq($r, 'INDETERMINATE_STATUS_COVERAGE')['affected_person_ids']);
    }

    public function test_relationship_starts_are_events_and_a_reappointment_creates_a_start_and_an_end(): void
    {
        $person = $this->createPersonRecord();
        $a = $this->createEmploymentRelationship($person, 'permanent', null, '2026-01-01');
        $this->setStatus($person, $a, 'on_duty', '2026-10-01');
        $this->setStatus($person, $a, 'contract_ended', '2026-11-15');
        $b = $this->createEmploymentRelationship($person, 'contract', null, '2026-11-15');
        [$mid] = $this->emp('2026-11-10');
        [$atStart] = $this->emp('2026-11-01');
        [$atNext] = $this->emp(self::N, onDuty: false);
        [$old] = $this->emp('2026-01-01');

        $r = $this->analytics();
        $starts = $r->sections['workforce_flows']['relationship_starts'];
        $ends = $r->sections['workforce_flows']['relationship_ends'];
        $startIds = array_column($starts['items'], 'employment_relationship_id');

        $this->assertContains($b->id, $startIds, 'the reappointment start is a Start event');
        $this->assertContains($this->rel($mid)['employment_relationship_id'], $startIds);
        $this->assertContains($this->rel($atStart)['employment_relationship_id'], $startIds, 'effective_from == month_start is in the month');
        $this->assertNotContains($a->id, $startIds);
        $this->assertNotContains($this->rel($old)['employment_relationship_id'], $startIds);
        $this->assertNull($this->recordOrNull($atNext), 'effective_from == next_month_start is next month');
        $this->assertSame('EVENT', $starts['grain']);
        $this->assertSame(count($starts['items']), $starts['event_count']);
        $this->assertContains($a->id, array_column($ends['items'], 'employment_relationship_id'), 'the same reappointment ends the previous relationship');
        $this->assertCount(1, array_filter($r->rows, fn ($row) => $row->personId === $person->id), 'the Person still counts once');
        $this->assertSame('contract_ended', $this->bucket($ends['items'], 'employment_relationship_id', $a->id)['reason']['status_code']);
        $this->assertSame(['person_id', 'employment_relationship_id', 'start_date', 'employment_type_code'], array_keys($starts['items'][0]), 'no Hire / New Employee naming and no first-vs-reappointment taxonomy');
        $this->assertStringNotContainsStringIgnoringCase('turnover', json_encode($r->sections));
        $this->assertStringNotContainsStringIgnoringCase('"hires"', json_encode($r->sections));
    }

    public function test_a_month_start_end_is_an_event_only_relationship_outside_the_population_and_overall_headcount(): void
    {
        [$person, $rel] = $this->emp();
        $this->setStatus($person, $rel, 'retired', self::N);
        [$mid, $m] = $this->emp();
        $this->setStatus($mid, $m, 'resigned', '2026-12-20');

        $december = $this->analytics('2026-12-01');
        $november = $this->analytics();
        $events = $december->sections['workforce_flows']['relationship_ends']['items'];
        $own = array_values(array_filter($events, fn ($e) => $e['employment_relationship_id'] === $rel->id));

        $this->assertNull($this->recordOrNull($person, '2026-12-01'), 'absent from the S37 population of December');
        $this->assertNotContains($rel->id, array_column($november->sections['workforce_flows']['relationship_ends']['items'], 'employment_relationship_id'), 'effective_to == next_month_start is not November\'s event');
        $this->assertCount(1, $own, 'exactly once');
        $this->assertFalse($own[0]['relationship_in_monthly_population']);
        $this->assertSame(['2026-12-01', '2026-11-30', 'retired'], [$own[0]['event_date'], $own[0]['last_employed_day'], $own[0]['reason']['status_code']]);
        $this->assertGreaterThanOrEqual(1, $december->sections['workforce_flows']['relationship_ends']['event_only_event_count']);
        $this->assertSame(count($december->rows), $december->sections['population']['overall_headcount'], 'event-only ends never change the overall headcount');
        foreach ($december->sections['employment_status']['status_exposure']['buckets'] as $b) {
            foreach ($b['persons'] ?? [] as $p) {
                $this->assertNotSame($person->id, $p);
            }
        }
        $this->assertNotContains($person->id, array_map(fn ($row) => $row->personId, $december->rows), 'no timeline, exposure or population for an event-only relationship');
        $this->assertSame(
            $december->sections['workforce_flows']['relationship_ends']['event_count'],
            $december->sections['workforce_flows']['relationship_ends']['in_monthly_population_event_count'] + $december->sections['workforce_flows']['relationship_ends']['event_only_event_count'],
        );
        $this->assertSame(['person_id', 'employment_type_code', 'employment_relationship_id', 'event_date', 'last_employed_day', 'reason', 'ended_terminally', 'relationship_in_monthly_population'], array_keys($own[0]));
    }

    public function test_end_reasons_are_distinct_and_a_missing_reason_is_not_recorded_with_data_quality(): void
    {
        [$retired, $a] = $this->emp();
        $this->setStatus($retired, $a, 'retired', '2026-11-12');
        [$martyr, $b] = $this->emp();
        $this->setStatus($martyr, $b, 'martyred', '2026-11-12');
        [$deceased, $c] = $this->emp();
        $this->setStatus($deceased, $c, 'deceased', '2026-11-12');
        [$direct, $d] = $this->emp();
        $this->end($direct, $d, '2026-11-12', terminal: true);
        [$directAtStart, $e] = $this->emp();
        $this->end($directAtStart, $e, self::M);
        [$unlisted, $f] = $this->emp();
        $this->setStatus($unlisted, $f, 'traveling', '2026-11-12');
        $this->end($unlisted, $f, '2026-11-12');
        $legacy = $this->createPersonRecord();
        $legacyId = $this->insertLegacy($legacy, '2020-01-01');

        $r = $this->analytics();
        $reasons = array_column($r->sections['workforce_flows']['relationship_ends']['by_reason'], 'event_count', 'reason');
        $items = $r->sections['workforce_flows']['relationship_ends']['items'];

        foreach (['RETIRED', 'MARTYRED', 'DECEASED'] as $reason) {
            $this->assertGreaterThanOrEqual(1, $reasons[$reason], "{$reason} keeps its own row (MARTYRED and DECEASED are never merged)");
        }
        $this->assertSame('NOT_RECORDED', $this->bucket($items, 'employment_relationship_id', $d->id)['reason']['state']);
        $this->assertTrue($this->bucket($items, 'employment_relationship_id', $d->id)['ended_terminally'], 'ended_terminally is a separate fact, never the reason');
        $this->assertSame('NOT_RECORDED', $this->bucket($items, 'employment_relationship_id', $e->id)['reason']['state'], 'a direct end at month_start (event-only) has no reason');
        $this->assertFalse($this->bucket($items, 'employment_relationship_id', $e->id)['relationship_in_monthly_population']);
        $this->assertSame('NOT_RECORDED', $this->bucket($items, 'employment_relationship_id', $f->id)['reason']['state'], 'a status at E that is not an ending status is not a reason');
        $dq = $this->dq($r, 'RELATIONSHIP_END_REASON_NOT_RECORDED');
        foreach ([$direct, $directAtStart, $unlisted] as $person) {
            $this->assertContains($person->id, $dq['affected_person_ids']);
        }
        $this->assertNotContains($retired->id, $dq['affected_person_ids']);
        $this->assertNotContains($legacyId, array_column($items, 'employment_relationship_id'), 'UNKNOWN_LEGACY has no known effective_to: never an event');
        $this->assertGreaterThanOrEqual(3, $dq['relationship_count']);
        $ids = array_column($items, 'employment_relationship_id');
        $this->assertSame(count($ids), count(array_unique($ids)), 'every terminal event appears exactly once (in-population and event-only alike)');
        $this->assertContains($a->id, $ids);
        $this->assertTrue($this->bucket($items, 'employment_relationship_id', $a->id)['relationship_in_monthly_population']);
    }

    // ------------------------------------------------------------------------------------------------------------
    // 5. Percentages
    // ------------------------------------------------------------------------------------------------------------

    public function test_every_percentage_has_an_explicit_denominator_and_the_right_semantics(): void
    {
        [$a] = $this->emp();
        $this->person($a, ['gender_id' => $this->gender('female')->id]);
        [$b, $rb] = $this->emp();
        $this->setStatus($b, $rb, 'traveling', '2026-11-10', '2026-11-12');
        $r = $this->analytics();

        $walk = function (array $node) use (&$walk): void {
            foreach ($node as $key => $value) {
                if (! is_array($value)) {
                    continue;
                }
                if (array_key_exists('basis_points', $value)) {
                    $this->assertSame('OVERALL_HEADCOUNT', $value['denominator_type'], "{$key}: explicit denominator type");
                    $this->assertSame($this->analytics()->overallHeadcount, $value['denominator_value'], "{$key}: explicit denominator value");
                    $this->assertArrayHasKey('numerator', $value);
                    $this->assertContains($key, ['percentage', 'exposure_share', 'direct_exposure_share', 'subtree_exposure_share', 'not_recorded_exposure_share'], "{$key}: only the two labelled kinds exist");
                    if ($key !== 'percentage') {
                        $this->assertSame(WorkforceAnalyticsResult::SHARE_EXPOSED_SEMANTICS, $value['semantics'], "{$key}: a multi-value share says what it is");
                    } else {
                        $this->assertArrayNotHasKey('semantics', $value);
                    }

                    continue;
                }
                $walk($value);
            }
        };
        $walk($r->sections);

        $json = json_encode($r->sections);
        $this->assertStringNotContainsString('distribution_percent', $json);
        $this->assertStringNotContainsString('distribution', strtolower($json));
        $gender = $r->sections['demographics']['gender']['buckets'];
        foreach ($gender as $bucket) {
            $this->assertSame($r->overallHeadcount, $bucket['percentage']['denominator_value']);
            $this->assertSame($bucket['person_count'], $bucket['percentage']['numerator']);
        }
        $this->assertContains('SHARE_OF_OVERALL_POPULATION_EXPOSED_TO_BUCKET', [$this->bucket($r->sections['employment_status']['status_exposure']['buckets'], 'bucket', 'TRAVELING')['exposure_share']['semantics']]);
    }

    public function test_single_value_percentages_sum_to_the_whole_and_multi_value_shares_may_exceed_it(): void
    {
        $people = [];
        for ($i = 0; $i < 4; $i++) {
            $people[] = $this->emp();
        }
        $this->person($people[0][0], ['gender_id' => $this->gender('female')->id]);
        foreach ($people as [$p, $rel]) {
            $this->setStatus($p, $rel, 'traveling', '2026-11-10', '2026-11-12');
        }
        $r = $this->analytics();
        $pointsOf = fn (array $buckets) => array_sum(array_map(fn ($b) => $b['percentage']['basis_points'], $buckets));

        $this->assertTrue($r->sections['demographics']['gender']['reconciles_to_overall_headcount']);
        $this->assertLessThanOrEqual(2, abs(10000 - $pointsOf($r->sections['demographics']['gender']['buckets'])), 'single-value percentages total 100% (up to the rounding of at most one basis point per bucket)');
        $shareSum = array_sum(array_map(fn ($b) => $b['exposure_share']['basis_points'], $r->sections['employment_status']['status_exposure']['buckets']));
        $this->assertGreaterThan(10000, $shareSum, 'multi-value exposure shares may exceed 100%');
        $this->assertFalse($r->sections['employment_status']['status_exposure']['buckets_reconcile_to_overall_headcount'], 'no false 100% reconciliation is claimed');
        $this->assertSame('MULTI_VALUE_TEMPORAL', $r->sections['employment_status']['status_exposure']['grain']);
    }

    public function test_percentages_are_exact_integer_arithmetic_rounded_half_up(): void
    {
        $ratio = new \ReflectionMethod(WorkforceAnalyticsSections::class, 'ratio');
        $ratio->setAccessible(true);
        $cases = [[1, 3, 3333, '33.33'], [2, 3, 6667, '66.67'], [1, 8, 1250, '12.50'], [1, 16, 625, '6.25'], [1, 32, 313, '3.13'], [0, 5, 0, '0.00'], [5, 5, 10000, '100.00'], [3, 2, 15000, '150.00'], [1, 7, 1429, '14.29']];

        foreach ($cases as [$n, $d, $bp, $percent]) {
            $this->assertSame(['basis_points' => $bp, 'percent' => $percent], $ratio->invoke(null, $n, $d), "{$n}/{$d}");
        }
        $this->assertSame(['basis_points' => null, 'percent' => null], $ratio->invoke(null, 3, 0), 'a zero denominator is safe');
    }

    // ------------------------------------------------------------------------------------------------------------
    // 6. Data quality
    // ------------------------------------------------------------------------------------------------------------

    public function test_data_quality_reuses_existing_codes_only_and_preserves_the_distinctions(): void
    {
        $r = $this->analytics();

        $this->assertSame(WorkforceAnalyticsResult::DATA_QUALITY_CODES, array_column($r->sections['data_quality'], 'code'));
        $this->assertSame([
            'INDETERMINATE_STATUS_COVERAGE', 'UNKNOWN_LEGACY_RELATIONSHIP_END', 'RELATIONSHIP_END_REASON_NOT_RECORDED', 'PRIMARY_QUALIFICATION_REQUIRED',
            'BIRTH_DATE_AFTER_REPORT_DATE', 'TRAVEL_PAY_STATUS_NOT_RECORDED', 'GENDER_NOT_RECORDED', 'ORGANIZATIONAL_PLACEMENT_NOT_RECORDED', 'ACTUAL_WORKPLACE_NOT_DETERMINABLE',
        ], WorkforceAnalyticsResult::DATA_QUALITY_CODES, 'every S44 code string is an existing earlier-stage code; none is new');
        $this->assertNotContains('RETURN_INTENTION_NOT_RECORDED', WorkforceAnalyticsResult::DATA_QUALITY_CODES);
        $this->assertStringNotContainsString('RETURN_INTENTION', json_encode($r->sections));
        foreach ($r->sections['data_quality'] as $entry) {
            $this->assertSame(['code', 'grain', 'person_count', 'relationship_count', 'affected_person_ids'], array_keys($entry), 'a deterministic shape');
            $this->assertSame($entry['person_count'], count($entry['affected_person_ids']));
            $this->assertSame($entry['affected_person_ids'], array_values(array_unique($entry['affected_person_ids'])));
            $sorted = $entry['affected_person_ids'];
            sort($sorted);
            $this->assertSame($sorted, $entry['affected_person_ids'], 'sorted ids');
            $this->assertSame(in_array($entry['code'], WorkforceAnalyticsResult::RELATIONSHIP_GRAIN_CODES, true), $entry['relationship_count'] !== null);
        }
    }

    public function test_unknown_legacy_keeps_the_end_unknown_and_never_fabricates_a_date_or_event(): void
    {
        $person = $this->createPersonRecord();
        $id = $this->insertLegacy($person, '2026-01-01');
        $r = $this->analytics();
        $rel = $this->rel($person);

        $this->assertNull($rel['effective_to']);
        $this->assertSame('UNKNOWN_LEGACY', $rel['end_knowledge_state']);
        $this->assertNull($rel['terminal_event']);
        $this->assertSame('INDETERMINATE', $this->record($person)->dutyClassification);
        $this->assertSame([['2026-11-01', '2026-12-01', 'INDETERMINATE']], array_map(fn ($s) => [$s['from'], $s['to'], $s['outcome']], $rel['status_segments']));
        $this->assertContains($person->id, $this->dq($r, 'UNKNOWN_LEGACY_RELATIONSHIP_END')['affected_person_ids']);
        $this->assertContains($person->id, $this->dq($r, 'INDETERMINATE_STATUS_COVERAGE')['affected_person_ids']);
        $this->assertNotContains($id, array_column($r->sections['workforce_flows']['relationship_ends']['items'], 'employment_relationship_id'));
        $this->assertSame('INCOMPLETE', $this->record($person)->service['state']);
    }

    // ------------------------------------------------------------------------------------------------------------
    // 7. API and security
    // ------------------------------------------------------------------------------------------------------------

    public function test_the_endpoint_returns_the_analytics_sections_without_person_rows_or_identity(): void
    {
        [$person, $rel] = $this->emp();
        $this->setStatus($person, $rel, 'resigned', '2026-11-20');
        $this->viewer();

        $json = $this->getJson(self::URL.'?month='.self::M)->assertOk()->json();

        foreach (['reporting_month', 'month_start', 'next_month_start', 'month_end', 'metadata', 'population', 'demographics', 'employment', 'qualifications', 'organization', 'actual_work', 'employment_status', 'workforce_flows', 'data_quality'] as $key) {
            $this->assertArrayHasKey($key, $json);
        }
        $this->assertArrayNotHasKey('rows', $json);
        $this->assertSame(self::M, $json['reporting_month']);
        $this->assertSame('2026-11-30', $json['month_end']);
        $this->assertSame('DISTINCT_PERSON', $json['metadata']['semantics']['overall_headcount']);
        $this->assertSame('OVERALL_HEADCOUNT', $json['metadata']['semantics']['percentage_denominator']);
        $this->assertSame('SHARE_OF_OVERALL_POPULATION_EXPOSED_TO_BUCKET', $json['metadata']['semantics']['multi_value_share']);
        $this->assertSame('EVENT_GRAIN', $json['metadata']['semantics']['relationship_starts_and_ends']);
        $this->assertSame($this->analytics()->sections['population']['overall_headcount'], $json['population']['overall_headcount']);
        $this->assertStringNotContainsString($person->national_id, json_encode($json), 'no national id');
        $this->assertStringNotContainsString('full_name', json_encode($json), 'no identity');
        $this->assertArrayHasKey('actual_workplaces', $json['actual_work']);
        $this->assertArrayHasKey('relationship_ends', $json['workforce_flows']);
    }

    public function test_the_endpoint_requires_its_dedicated_permission_a_valid_month_and_authentication(): void
    {
        $this->getJson(self::URL.'?month='.self::M)->assertStatus(401);

        $this->viewer([Perm::MONTHLY_EMPLOYMENT_STATUS_REPORT_VIEW, Perm::MONTHLY_ADMINISTRATIVE_REPORT_VIEW, Perm::HUMAN_CADRE_VIEW, Perm::PERSONS_VIEW]);
        $this->getJson(self::URL.'?month='.self::M)->assertStatus(403);

        $this->viewer();
        $statements = $this->statuses(function (): void {
            foreach (['', 'month=2026-11', 'month=2026-11-15', 'month=garbage', 'month=2026-13-01', 'month[]=2026-11-01'] as $query) {
                $this->getJson(self::URL.($query === '' ? '' : '?'.$query))->assertStatus(422)->assertJsonValidationErrors(['month']);
            }
        });
        $this->assertCount(0, array_filter($statements, fn ($sql) => str_contains($sql, 'hr.employment_relationships')), 'an invalid month is rejected before S37 runs');
        $this->getJson(self::URL.'?month='.self::M)->assertOk();
    }

    public function test_the_permission_exists_and_is_granted_to_no_role(): void
    {
        $this->assertSame(1, (int) DB::table('security.permissions')->where('code', 'hr.workforce_analytics.view')->count());
        $this->assertSame('hr.workforce_analytics.view', Perm::WORKFORCE_ANALYTICS_VIEW);
        $this->assertContains(Perm::WORKFORCE_ANALYTICS_VIEW, Perm::ALL);
        $this->assertSame(0, (int) DB::table('security.role_permissions as rp')->join('security.permissions as p', 'p.id', '=', 'rp.permission_id')->where('p.code', 'hr.workforce_analytics.view')->count(), 'no business-role auto grant');
    }

    public function test_the_route_is_one_read_only_get_and_the_only_public_input_is_month(): void
    {
        $matches = array_values(array_filter(iterator_to_array(Route::getRoutes()), fn ($route) => $route->uri() === 'api/v1/hr/workforce-analytics'));
        $this->assertCount(1, $matches);

        $this->assertSame(['GET', 'HEAD'], $matches[0]->methods());
        $this->assertContains('permission:'.Perm::WORKFORCE_ANALYTICS_VIEW, $matches[0]->gatherMiddleware());
        $this->assertSame([], glob(base_path('app/Modules/*/Presentation/*/*Monthly*.php')), 'no Monthly* presentation class');
        $this->assertSame('workforce-analytics', substr($matches[0]->uri(), -19), 'no route exception was needed for this URI');

        $this->viewer();
        $plain = $this->getJson(self::URL.'?month='.self::M)->json('population.overall_headcount');
        $this->assertSame($plain, $this->getJson(self::URL.'?month='.self::M.'&per_page=1&page=2&sort=name&format=xlsx&group_by=gender&from=2020-01-01&to=2030-01-01&gender=MALE')->json('population.overall_headcount'), 'filters, grouping, ranges and export flags are ignored');
        foreach (['post', 'put', 'patch', 'delete'] as $method) {
            $this->{$method.'Json'}(self::URL.'?month='.self::M)->assertStatus(405);
        }
    }

    // ------------------------------------------------------------------------------------------------------------
    // 8. Query architecture
    // ------------------------------------------------------------------------------------------------------------

    /** One relationship exercising every S44 batch: history, status, qualification, placement, hierarchy, a month-start end and an ending status. */
    private function workload(int $people): void
    {
        for ($i = 0; $i < $people; $i++) {
            [$p, $rel] = $this->emp('2026-01-01', $i % 2 === 0 ? 'permanent' : 'contract');
            $unit = $this->createUnit('م'.$i);
            $this->recordPlacement($rel, $unit, '2026-02-01');
            app(RecordPersonQualification::class)->handle($p, $this->createSyntheticAcademicDegree(), null, null, $this->syntheticActorPrincipalId());
            $this->setStatus($p, $rel, 'traveling', '2026-11-05', '2026-11-08');
            app(StartFullSecondment::class)->handle($rel->refresh(), $this->createUnit('و'.$i), '2026-11-10');
            $this->setStatus($p, $rel, 'resigned', '2026-11-25');
            [$q, $qRel] = $this->emp();
            $this->setStatus($q, $qRel, 'retired', self::M);
        }
    }

    public function test_the_statement_count_is_constant_independent_of_population_size(): void
    {
        $this->workload(2);
        $small = $this->statuses(fn () => $this->analytics());
        $this->workload(10);
        $large = $this->statuses(fn () => $this->analytics());

        $this->assertCount(count($small), $large, 'no N+1: the count does not depend on Persons, relationships, units, qualifications, statuses or events');
        $this->assertCount(23, $large, 'S37 (8) + S40 (7) + gender catalog + relationship history + status periods + Primary Qualifications + placement + hierarchy + supplemental ends + ending-status behavior');
        foreach ($large as $sql) {
            $this->assertMatchesRegularExpression('/^(select|with)\b/', strtolower(ltrim($sql)), 'read-only');
        }
        foreach ([
            'SELECT r.id, r.person_id, r.employment_type_id' => 1, // the one S37 population statement
            'FROM hr.employment_job_title_periods p' => 1, // S40 ran once, on the precomputed population
            'SELECT g.id, g.code, g.name_ar, g.name_en FROM ref.genders g' => 1,
            // S48 (§S48.3, D13): this consumer now reads the current-version view, not the identity table directly.
            'FROM hr.person_qualifications_current pq' => 2, // S37's qualifications + the Primary Qualification batch
            'FROM hr.organizational_placement_periods pp' => 1,
            'WITH RECURSIVE tree AS' => 1,
            'LEFT JOIN hr.employment_status_periods sp ON' => 1,
            'FROM ref.employment_status_detail_behaviors b' => 1,
        ] as $needle => $times) {
            $this->assertCount($times, array_filter($large, fn ($sql) => str_contains($sql, $needle)), "{$needle} runs {$times} time(s)");
        }
    }

    public function test_the_count_is_constant_with_no_terminal_events_and_with_an_empty_population(): void
    {
        $count = fn (int $people) => (function () use ($people) {
            for ($i = 0; $i < $people; $i++) {
                $this->emp();
            }

            return count($this->statuses(fn () => $this->analytics()));
        })();
        $a = $count(1);
        $b = $count(8);

        $this->assertSame($a, $b);
        $this->assertSame(21, $a, 'without an ending status the behavior batch is skipped; no hierarchy statement without a unit');
        $this->assertCount(2, $this->statuses(fn () => $this->analytics('2020-01-01')), 'an empty population: the S37 relationship statement and the supplemental ends batch; every enrichment batch is skipped');
    }

    public function test_s37_runs_exactly_once_and_s40_consumes_the_precomputed_population(): void
    {
        $this->emp();

        $statements = $this->statuses(fn () => $this->analytics());

        $this->assertCount(1, array_filter($statements, fn ($sql) => str_contains($sql, 'SELECT r.id, r.person_id, r.employment_type_id')), 'the canonical S37 population is computed once');
        $this->assertCount(1, array_filter($statements, fn ($sql) => str_contains($sql, 'FROM hr.employment_category_periods p')), 'S40 enrichment ran once');
        $this->assertCount(8, $this->statuses(fn () => app(ListMonthlyReportingPopulation::class)(self::M)), 'S37 keeps its eight statements');
        $this->assertCount(15, $this->statuses(fn () => app(ListMonthlyWorkforceDimensions::class)(self::M)), 'the existing S40 path is unchanged');
    }

    public function test_s44_composes_existing_components_and_contains_no_report_call_duty_engine_or_forbidden_concept(): void
    {
        $code = preg_replace('#/\*.*?\*/|//[^\n]*#s', '', (string) file_get_contents(base_path(self::BUILDER)));

        $this->assertSame(1, substr_count($code, '($this->population)('), 'ListMonthlyReportingPopulation is called exactly once');
        $this->assertSame(1, substr_count($code, '->fromPopulation('), 'S40 is reused through the additive precomputed-population entry point');
        foreach (['BuildHumanCadreResult', 'BuildAdministrativeReportResult', 'BuildEmploymentStatusReportResult', 'BuildMonthlyNotOnDutyResult', 'Controller', 'Http::', 'Route::', 'MonthlyDutyClassification', 'MonthlyStatusSegmentation::segment(', 'ListReportingPopulationAsOf', 'ResolveEffective', 'ResolveReturnIntentionAsOf', 'ResolveEmployment', 'ReturnIntention', 'return_intention', 'ListMonthlyReportingPopulation::'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $code, "S44 re-derives or composes nothing: {$forbidden}");
        }
        $this->assertSame(8, substr_count($code, 'DB::select('), 'exactly the eight S44-owned set-based batches');
        foreach (['attendance', 'timesheet', 'payroll', 'vacanc', 'occupancy', 'turnover', 'forecast', 'xlsx', 'pdf', 'leave_balance', 'absence'] as $excluded) {
            $this->assertStringNotContainsString($excluded, strtolower($code), "excluded concept: {$excluded}");
        }
        $this->assertDoesNotMatchRegularExpression('/\bfte\b/i', $code, 'excluded concept: FTE');
    }

    public function test_s44_adds_no_business_schema_and_no_frontend_or_output_file(): void
    {
        // S48 (docs/person-qualification-history-foundation-specification.md) added its own later
        // migrations and one view (hr.person_qualifications_current, §S48.3); neither is an S44
        // business-schema addition, so this asserts S44's OWN migration footprint by name rather
        // than by "nothing was ever added after it" (which S48 legitimately does).
        $this->assertSame(['2026_10_21_000001_seed_security_workforce_analytics_permission.php'], collect(glob(base_path('database/migrations/*.php')))->map('basename')->filter(fn ($f) => str_contains($f, 'workforce_analytics'))->values()->all(), 'the only S44 migration is the permission seed');
        $this->assertSame(0, (int) DB::selectOne('select count(*) as c from pg_matviews')->c);
        $this->assertSame(['person_qualifications_current'], DB::table('information_schema.views')->whereIn('table_schema', ['hr', 'ref', 'org', 'automation', 'reporting'])->pluck('table_name')->all(), 'no view other than S48\'s person_qualifications_current');
        $this->assertSame(0, DB::table('information_schema.tables')->whereIn('table_schema', ['hr', 'ref', 'org', 'automation', 'reporting'])->where('table_name', 'like', '%analytic%')->count(), 'no analytics table');
        foreach (glob(base_path('../frontend/src/*/*.ts*')) ?: [] as $file) {
            // S45 (Dashboard Foundation) is the authorized consumer of this endpoint: its page test is the ONE exact-path exception.
            if (str_ends_with(str_replace('\\', '/', $file), 'src/pages/DashboardPage.test.tsx')) {
                continue;
            }
            $this->assertStringNotContainsString('workforce-analytics', (string) file_get_contents($file), 'no frontend consumer');
        }
        foreach (['Pdf', 'Xlsx', 'Csv', 'Export', 'Dashboard', 'Chart', 'Print'] as $forbidden) {
            $this->assertSame([], array_values(array_filter(glob(base_path('app/Modules/HumanResources/*/*/*WorkforceAnalytics*')), fn ($f) => str_contains($f, $forbidden))));
        }
        $this->assertSame(0, (int) DB::table('security.permissions')->where('code', 'like', '%vacanc%')->orWhere('code', 'like', '%turnover%')->orWhere('code', 'like', '%attendance%')->count());
    }
}
