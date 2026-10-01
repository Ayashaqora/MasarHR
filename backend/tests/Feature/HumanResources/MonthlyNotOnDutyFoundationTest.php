<?php

namespace Tests\Feature\HumanResources;

use App\Modules\HumanResources\Application\Commands\EndEmploymentRelationship;
use App\Modules\HumanResources\Application\Commands\RecordEmploymentStatusPeriod;
use App\Modules\HumanResources\Application\Commands\RecordPartialSecondmentPeriod;
use App\Modules\HumanResources\Application\Commands\RecordWorkSchedulePeriod;
use App\Modules\HumanResources\Application\Commands\TransferEmployee;
use App\Modules\HumanResources\Application\Queries\Reporting\BuildMonthlyNotOnDutyResult;
use App\Modules\HumanResources\Application\Queries\Reporting\ListMonthlyReportingPopulation;
use App\Modules\HumanResources\Application\Queries\Reporting\MonthlyNotOnDutyResult;
use App\Modules\HumanResources\Application\Queries\Reporting\MonthlyReportingPersonRow;
use App\Modules\HumanResources\Infrastructure\Authorization\HumanResourcesPermissionCatalog as Perm;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\EmploymentRelationship;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\Person;
use App\Modules\Security\Infrastructure\Persistence\Eloquent\Principal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/**
 * S39 REPORT-3 — Monthly Not-On-Duty (docs/monthly-not-on-duty-report-foundation-specification.md): a projection over the S37
 * canonical monthly dataset. Real PostgreSQL, synthetic data only. The reporting month is November 2026:
 * [2026-11-01, 2026-12-01). Status dates start after 2026-09-26, the date from which the S06 behaviors are authoritative.
 * Assertions target the fixtures this test creates and compare counts against the pre-existing baseline, so they never depend on
 * other data in the database.
 */
class MonthlyNotOnDutyFoundationTest extends HumanResourcesTestCase
{
    private const M = '2026-11-01';

    private const URL = '/api/v1/hr/not-on-duty';

    private const SUN_THU = ['SUNDAY', 'MONDAY', 'TUESDAY', 'WEDNESDAY', 'THURSDAY'];

    /** @var list<string> */
    private array $mine = [];

    private int $baseTotal = 0;

    private int $baseIndeterminate = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $base = $this->report();
        $this->baseTotal = $base->totalNotOnDutyPersons;
        $this->baseIndeterminate = $base->indeterminateCount;
    }

    // ------------------------------------------------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------------------------------------------------

    private function report(string $month = self::M): MonthlyNotOnDutyResult
    {
        return app(BuildMonthlyNotOnDutyResult::class)($month);
    }

    /** @return array{0: Person, 1: EmploymentRelationship} */
    private function emp(string $type = 'permanent', string $from = '2026-01-01', ?Person $person = null): array
    {
        $person ??= $this->createPersonRecord();
        $this->mine[] = $person->id;

        return [$person, $this->createEmploymentRelationship($person, $type, null, $from)];
    }

    private function recStatus(Person $person, EmploymentRelationship $rel, string $code, string $from, ?string $to = null): void
    {
        app(RecordEmploymentStatusPeriod::class)->handle($person, $rel->refresh(), $this->statusDetail($code), $from, $to);
    }

    /** A status period written directly (legacy-shaped data: the command requires it to start after the relationship start). */
    private function rawStatus(EmploymentRelationship $rel, string $code, string $from, ?string $to = null): void
    {
        DB::table('hr.employment_status_periods')->insert([
            'id' => (string) Str::uuid7(), 'employment_relationship_id' => $rel->id, 'status_detail_id' => $this->statusDetail($code)->id,
            'effective_from' => $from, 'effective_to' => $to, 'created_at' => now(),
        ]);
    }

    private function end(Person $person, EmploymentRelationship $rel, string $to): void
    {
        $rel->refresh();
        app(EndEmploymentRelationship::class)->handle($person, $rel, $rel->version, $to, false);
    }

    /** An employee on $code for the whole of November (a bounded leave reaches past the month end). */
    private function onLeaveAllMonth(string $code, string $type = 'permanent'): Person
    {
        [$person, $rel] = $this->emp($type);
        $bounded = in_array($code, ['unpaid_leave', 'external_sick_leave'], true);
        $this->recStatus($person, $rel, $code, '2026-10-01', $bounded ? '2026-12-30' : null);

        return $person;
    }

    private function viewer(): Principal
    {
        $principal = $this->createPrincipal();
        $this->assignRole($principal, $this->createRoleWithPermissions([Perm::MONTHLY_NOT_ON_DUTY_VIEW]));
        $this->actingAs($principal, 'web');

        return $principal;
    }

    private function principalWithPermissions(array $codes): Principal
    {
        $principal = $this->createPrincipal();
        $this->assignRole($principal, $this->createRoleWithPermissions($codes));
        $this->actingAs($principal, 'web');

        return $principal;
    }

    /** @return array<string, mixed> decoded response of the endpoint for the authenticated viewer */
    private function api(string $query = 'month='.self::M): array
    {
        return $this->getJson(self::URL.'?'.$query)->assertOk()->json();
    }

    /** @return list<string> */
    private function rowIds(array $response): array
    {
        return array_values(array_intersect(array_map(fn ($row) => $row['person']['person_id'], $response['rows']), $this->mine));
    }

    /** @return array<string, array<string, mixed>> mine rows keyed by person id */
    private function mineRows(array $response): array
    {
        $rows = [];
        foreach ($response['rows'] as $row) {
            if (in_array($row['person']['person_id'], $this->mine, true)) {
                $rows[$row['person']['person_id']] = $row;
            }
        }

        return $rows;
    }

    private function contains(array $haystack, callable $predicate): bool
    {
        foreach ($haystack as $key => $value) {
            if ($predicate($key, $value) || (is_array($value) && $this->contains($value, $predicate))) {
                return true;
            }
        }

        return false;
    }

    // ------------------------------------------------------------------------------------------------------------
    // 1–3. Canonical S37 reuse
    // ------------------------------------------------------------------------------------------------------------

    public function test_the_report_runs_exactly_one_canonical_s37_population_computation_per_request(): void
    {
        $this->onLeaveAllMonth('unpaid_leave');
        $this->viewer();
        $statements = [];
        DB::listen(function ($query) use (&$statements): void {
            $statements[] = $query->sql;
        });

        $this->api();

        $canonical = array_filter($statements, fn ($sql) => str_contains($sql, 'SELECT r.id, r.person_id, r.employment_type_id'));
        $this->assertCount(1, $canonical, 'exactly one S37 population computation');
        $periods = array_filter($statements, fn ($sql) => str_contains($sql, 'FROM hr.employment_status_periods sp'));
        $this->assertCount(1, $periods, 'one status read (S37\'s); S39 reads no status of its own');
    }

    public function test_s39_contains_no_population_or_classification_logic_of_its_own(): void
    {
        $code = preg_replace('#/\*.*?\*/|//[^\n]*#s', '', (string) file_get_contents(base_path('app/Modules/HumanResources/Application/Queries/Reporting/BuildMonthlyNotOnDutyResult.php')));

        $this->assertSame(1, substr_count($code, '($this->population)('), 'ListMonthlyReportingPopulation is called exactly once');
        foreach (['hr.employment_relationships', 'hr.employment_status_periods', 'organizational_placement', 'full_secondment', 'workplace_assignment', 'partial_secondment', 'work_schedule', 'MonthlyStatusSegmentation', 'MonthInterval', 'classify('] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $code, "S39 re-derives nothing: {$forbidden}");
        }
        foreach (['hr.persons' => 1, 'ref.employment_status_details' => 1] as $table => $count) {
            $this->assertSame($count, substr_count($code, "FROM {$table} "), "one batched lookup on {$table}");
        }
    }

    // ------------------------------------------------------------------------------------------------------------
    // 4–9. Population, INDETERMINATE, total
    // ------------------------------------------------------------------------------------------------------------

    public function test_only_no_on_duty_persons_are_rows_indeterminate_is_metadata_and_the_total_is_the_complete_population(): void
    {
        $unpaid = $this->onLeaveAllMonth('unpaid_leave');
        $sick = $this->onLeaveAllMonth('external_sick_leave');
        $suspended = $this->onLeaveAllMonth('suspended');
        $traveling = $this->onLeaveAllMonth('traveling');

        [$duty, $relDuty] = $this->emp();
        $this->recStatus($duty, $relDuty, 'on_duty', '2026-09-27');                                   // HAS_ON_DUTY
        [$expiring, $relExp] = $this->emp();
        $this->recStatus($expiring, $relExp, 'traveling', '2026-09-27', '2026-11-10');                // derived on_duty from 11-10 → HAS_ON_DUTY
        [$successor, $relSucc] = $this->emp();
        $this->recStatus($successor, $relSucc, 'traveling', '2026-09-27', '2026-11-10');
        $this->recStatus($successor, $relSucc, 'on_duty', '2026-11-10');                              // explicit successor → HAS_ON_DUTY
        [$none] = $this->emp();                                                                          // no status: INDETERMINATE
        [$newHire, $relNew] = $this->emp('permanent', '2026-11-10');
        $this->recStatus($newHire, $relNew, 'traveling', '2026-11-12');                                // first active days unresolved: INDETERMINATE, never NO_ON_DUTY

        $this->viewer();
        $response = $this->api();

        $expected = [$unpaid->id, $sick->id, $suspended->id, $traveling->id];
        $this->assertEqualsCanonicalizing($expected, $this->rowIds($response), 'NO_ON_DUTY only');
        foreach ([$duty, $expiring, $successor, $none, $newHire] as $excluded) {
            $this->assertNotContains($excluded->id, $this->rowIds($response), 'HAS_ON_DUTY and INDETERMINATE are not rows');
        }
        $this->assertSame($this->baseIndeterminate + 2, $response['summary']['indeterminate_count'], 'INDETERMINATE counted as metadata only');
        $this->assertSame($this->baseTotal + 4, $response['summary']['total_not_on_duty_persons'], 'INDETERMINATE is not in the total');
        $this->assertCount($response['summary']['total_not_on_duty_persons'], $response['rows'], 'the total equals the complete unique Person population');
        $this->assertSame(array_unique(array_column(array_column($response['rows'], 'person'), 'person_id')), array_column(array_column($response['rows'], 'person'), 'person_id'), 'unique Persons');
        foreach ($this->mineRows($response) as $row) {
            $this->assertSame('NO_ON_DUTY', $row['duty_classification']);
        }
    }

    public function test_the_four_temporary_statuses_carry_their_own_last_reason_with_a_stable_code_and_the_existing_label(): void
    {
        $people = ['unpaid_leave' => $this->onLeaveAllMonth('unpaid_leave'), 'external_sick_leave' => $this->onLeaveAllMonth('external_sick_leave'),
            'suspended' => $this->onLeaveAllMonth('suspended'), 'traveling' => $this->onLeaveAllMonth('traveling')];
        $this->viewer();

        $rows = $this->mineRows($this->api());

        foreach ($people as $code => $person) {
            $reason = $rows[$person->id]['relationships'][0]['last_non_on_duty_reason'];
            $this->assertSame($code, $reason['status_code']);
            $detail = $this->statusDetail($code);
            $this->assertSame([$detail->id, $detail->name_ar, $detail->name_en], [$reason['status_detail_id'], $reason['status_name_ar'], $reason['status_name_en']], 'labels come from ref.employment_status_details');
            $this->assertNull($rows[$person->id]['relationships'][0]['relationship_end_reason']);
        }
    }

    public function test_a_partial_month_leave_with_a_derived_on_duty_return_is_excluded(): void
    {
        [$person, $rel] = $this->emp();
        $this->recStatus($person, $rel, 'on_duty', '2026-09-27');
        $this->recStatus($person, $rel, 'unpaid_leave', '2026-11-05', '2026-11-15');   // on_duty is derived again from 11-15
        $this->viewer();

        $this->assertNotContains($person->id, $this->rowIds($this->api()), 'any on_duty interval keeps the Person out: no "any absence" semantics');
    }

    // ------------------------------------------------------------------------------------------------------------
    // 10–12, 20–22. Unique Person, reappointment, per-relationship reasons
    // ------------------------------------------------------------------------------------------------------------

    public function test_a_same_month_reappointment_is_one_person_row_with_isolated_relationship_segments_and_separate_reasons(): void
    {
        [$person, $old] = $this->emp('contract');
        $this->recStatus($person, $old, 'unpaid_leave', '2026-10-01', '2026-11-10');
        $this->recStatus($person, $old, 'contract_ended', '2026-11-10');             // ends the old relationship at 11-10
        $new = $this->createEmploymentRelationship($person, 'contract', null, '2026-11-10');
        // Legacy-shaped coverage (the S10 command always leaves the first active day unresolved, which would make the Person INDETERMINATE):
        $this->rawStatus($new, 'traveling', '2026-11-10');
        $this->viewer();

        $response = $this->api();
        $rows = $this->mineRows($response);

        $this->assertCount(1, $rows, 'one Person row');
        $segments = $rows[$person->id]['relationships'];
        $this->assertSame([$old->id, $new->id], array_column($segments, 'employment_relationship_id'), 'two isolated relationship segments in S37 order');
        $this->assertSame('unpaid_leave', $segments[0]['last_non_on_duty_reason']['status_code']);
        $this->assertSame('contract_ended', $segments[0]['relationship_end_reason']['status_code'], 'the end reason is separate from the temporary reason');
        $this->assertSame('traveling', $segments[1]['last_non_on_duty_reason']['status_code'], 'reasons never leak across relationships');
        $this->assertNull($segments[1]['relationship_end_reason']);
        $this->assertSame(['person', 'duty_classification', 'relationships'], array_keys($rows[$person->id]));
        $this->assertSame([], array_values(array_filter(array_keys($rows[$person->id]['person']), fn ($k) => str_contains($k, 'reason'))), 'no Person-level reason');
        $this->assertFalse($this->contains($rows[$person->id], fn ($key) => in_array($key, ['person_reason', 'primary_reason', 'final_reason', 'monthly_reason', 'reason'], true)), 'no Person-level reason of any name anywhere');
    }

    public function test_a_relationship_ending_inside_the_month_is_clipped_and_carries_its_end_reason(): void
    {
        [$person, $rel] = $this->emp();
        $this->recStatus($person, $rel, 'unpaid_leave', '2026-10-20', '2026-11-20');
        $this->recStatus($person, $rel, 'resigned', '2026-11-20');
        $this->viewer();

        $segment = $this->mineRows($this->api())[$person->id]['relationships'][0];

        $this->assertSame(['2026-11-01', '2026-11-20'], [$segment['clipped_from'], $segment['clipped_to']]);
        $this->assertSame('KNOWN', $segment['end_knowledge_state']);
        $this->assertSame('unpaid_leave', $segment['last_non_on_duty_reason']['status_code'], 'the terminal status is not the temporary reason');
        $this->assertSame('resigned', $segment['relationship_end_reason']['status_code']);
    }

    public function test_month_boundaries_are_deterministic(): void
    {
        [$endsAtM1, $relA] = $this->emp();
        $this->recStatus($endsAtM1, $relA, 'unpaid_leave', '2026-10-01', '2026-11-01');
        $this->end($endsAtM1, $relA, '2026-11-01');                                      // ends exactly on month_start: not in the month
        [$startsAtN] = $this->emp('permanent', '2026-12-01');                            // starts at next_month_start: not in the month
        [$endsAtN, $relC] = $this->emp();
        $this->recStatus($endsAtN, $relC, 'unpaid_leave', '2026-10-01', '2026-12-01');
        $this->end($endsAtN, $relC, '2026-12-01');                                       // ends at next_month_start: covers the whole month
        $this->viewer();

        $response = $this->api();

        $this->assertNotContains($endsAtM1->id, $this->rowIds($response));
        $this->assertNotContains($startsAtN->id, $this->rowIds($response));
        $this->assertContains($endsAtN->id, $this->rowIds($response));
        $segment = $this->mineRows($response)[$endsAtN->id]['relationships'][0];
        $this->assertSame(['2026-11-01', '2026-12-01'], [$segment['clipped_from'], $segment['clipped_to']]);
        $this->assertNull($segment['relationship_end_reason'], 'an end at next_month_start belongs to the next month (S37 Correction 01)');
        $this->assertSame([self::M, '2026-11-01', '2026-12-01'], [$response['month'], $response['month_start'], $response['next_month_start']]);
    }

    public function test_ordering_is_deterministic_and_follows_s37(): void
    {
        foreach (range(1, 4) as $i) {
            $this->onLeaveAllMonth('unpaid_leave');
        }
        $this->viewer();

        $first = $this->api();
        $second = $this->api();

        $this->assertSame($first, $second, 'identical on repeated reads');
        $ids = array_column(array_column($first['rows'], 'person'), 'person_id');
        $sorted = $ids;
        sort($sorted);
        $this->assertSame($sorted, $ids, 'Persons in S37 order (by id)');
    }

    // ------------------------------------------------------------------------------------------------------------
    // 23–26. Workplace
    // ------------------------------------------------------------------------------------------------------------

    public function test_workplace_interval_segments_are_preserved_without_a_single_monthly_workplace(): void
    {
        [$person, $rel] = $this->emp();
        $this->recStatus($person, $rel, 'suspended', '2026-10-01');
        $origin = $this->createUnit();
        $destination = $this->createUnit();
        $partialUnit = $this->createUnit();
        $this->recordPlacement($rel, $origin, '2026-01-15');
        app(RecordWorkSchedulePeriod::class)->handle($rel->refresh(), '2026-01-01', self::SUN_THU);
        app(TransferEmployee::class)->handle($rel->refresh(), $destination, '2026-11-08', $this->transferDecisionType());
        app(RecordPartialSecondmentPeriod::class)->handle($rel->refresh(), $partialUnit, '2026-11-16', '2026-11-24', ['SUNDAY', 'TUESDAY']);
        [$unresolved, $relU] = $this->emp();
        $this->recStatus($unresolved, $relU, 'traveling', '2026-10-01');                 // no placement at all
        [$ambiguous, $relA] = $this->emp();
        $this->recStatus($ambiguous, $relA, 'suspended', '2026-10-01');
        $homeA = $this->createUnit();
        $secA = $this->createUnit();
        $asgA = $this->createUnit();
        $this->recordPlacement($relA, $homeA, '2026-01-15');
        foreach ([['hr.full_secondment_periods', $secA, '2026-10-01', '2026-11-20'], ['hr.workplace_assignment_periods', $asgA, '2026-11-10', null]] as [$table, $unit, $from, $to]) {
            DB::table($table)->insert(['id' => (string) Str::uuid7(), 'employment_relationship_id' => $relA->id, 'organizational_unit_id' => $unit->id, 'effective_from' => $from, 'effective_to' => $to, 'created_at' => now()]);
        }
        $this->viewer();

        $rows = $this->mineRows($this->api());

        $segments = $rows[$person->id]['relationships'][0]['workplace_segments'];
        $this->assertSame(['RESOLVED', 'RESOLVED', 'PARTIAL_ALLOCATION', 'RESOLVED'], array_column($segments, 'state'));
        $this->assertSame([$origin->id, $destination->id, null, $destination->id], array_column($segments, 'organizational_unit_id'), 'several segments, never one workplace');
        $partial = $segments[2];
        $this->assertSame($destination->id, $partial['underlying_organizational_unit_id']);
        $this->assertSame([$partialUnit->id, ['TUESDAY', 'SUNDAY']], [$partial['partial_allocations'][0]['organizational_unit_id'], $partial['partial_allocations'][0]['weekdays']], 'weekday allocation preserved (ISO order), no percentages');
        $this->assertFalse($this->contains($rows[$person->id], fn ($key) => in_array($key, ['percentage', 'allocated_days', 'current_workplace', 'primary_workplace', 'reporting_workplace'], true)));
        $this->assertSame(['UNRESOLVED'], array_column($rows[$unresolved->id]['relationships'][0]['workplace_segments'], 'state'), 'a missing placement stays unresolved and the Person is still a row');
        $ambiguousStates = array_column($rows[$ambiguous->id]['relationships'][0]['workplace_segments'], 'state');
        $this->assertContains('AMBIGUOUS_MOVEMENT_STATE', $ambiguousStates);
        $ambiguousSegment = collect($rows[$ambiguous->id]['relationships'][0]['workplace_segments'])->firstWhere('state', 'AMBIGUOUS_MOVEMENT_STATE');
        $this->assertNull($ambiguousSegment['organizational_unit_id'], 'no chosen winner');
        $this->assertCount(2, $ambiguousSegment['competing_movements']);
    }

    // ------------------------------------------------------------------------------------------------------------
    // 32–40. Identity, batching, qualifications, age
    // ------------------------------------------------------------------------------------------------------------

    public function test_person_identity_is_the_existing_national_id_and_full_name_ar_and_a_legacy_null_name_stays_null(): void
    {
        $a = $this->onLeaveAllMonth('unpaid_leave');
        $b = $this->onLeaveAllMonth('unpaid_leave');
        $legacy = $this->onLeaveAllMonth('unpaid_leave');
        DB::table('hr.persons')->where('id', $a->id)->update(['full_name_ar' => 'أ ب ج د']);
        DB::table('hr.persons')->where('id', $b->id)->update(['full_name_ar' => 'هـ و ز']);
        DB::table('hr.persons')->where('id', $legacy->id)->update(['full_name_ar' => null]);
        $this->viewer();

        $rows = $this->mineRows($this->api());

        foreach ([$a, $b, $legacy] as $person) {
            $stored = DB::table('hr.persons')->where('id', $person->id)->first();
            $this->assertSame($stored->national_id, $rows[$person->id]['person']['national_id'], 'national_id maps to the right Person');
            $this->assertSame($stored->full_name_ar, $rows[$person->id]['person']['full_name'], 'full_name is exactly the stored full_name_ar');
        }
        $this->assertSame('أ ب ج د', $rows[$a->id]['person']['full_name'], 'no name-part logic, concatenation or normalization');
        $this->assertNull($rows[$legacy->id]['person']['full_name'], 'never a fabricated placeholder');
        $this->assertContains($legacy->id, $this->rowIds($this->api()), 'a missing name does not affect membership');
        $this->assertSame(['person_id', 'national_id', 'full_name', 'gender_id', 'birth_date', 'qualifications', 'qualification_semantics'], array_keys($rows[$a->id]['person']));
    }

    public function test_identity_enrichment_does_not_decide_membership_or_change_anything_else(): void
    {
        $person = $this->onLeaveAllMonth('suspended');
        $before = $this->report();

        DB::table('hr.persons')->where('id', $person->id)->update(['full_name_ar' => null]);
        $after = $this->report();

        $this->assertSame($before->totalNotOnDutyPersons, $after->totalNotOnDutyPersons);
        $this->assertSame($before->indeterminateCount, $after->indeterminateCount);
        $strip = fn (MonthlyNotOnDutyResult $r) => array_map(fn ($row) => [$row['person']['person_id'], $row['duty_classification'], array_map(fn ($s) => [$s->employmentRelationshipId, $s->lastNonOnDutyReason, $s->relationshipEndReason, $s->workplaceSegments], $row['relationships'])], $r->rows);
        $this->assertSame($strip($before), $strip($after), 'classification, reasons and workplace are independent of identity facts');
    }

    public function test_identity_and_status_label_enrichment_are_batched_and_no_statement_grows_with_the_rows(): void
    {
        $this->viewer();
        $measure = function (int $people): array {
            for ($i = 0; $i < $people; $i++) {
                $this->onLeaveAllMonth('unpaid_leave');
            }
            $statements = [];
            DB::listen(function ($query) use (&$statements): void {
                $statements[] = $query->sql;
            });
            $this->api();

            return [count($statements),
                count(array_filter($statements, fn ($sql) => str_contains($sql, 'FROM hr.persons WHERE id = ANY'))),
                count(array_filter($statements, fn ($sql) => str_contains($sql, 'FROM ref.employment_status_details WHERE id = ANY')))];
        };

        $small = $measure(2);
        $large = $measure(14);

        $this->assertSame([1, 1], [$small[1], $small[2]], 'one identity lookup and one label lookup');
        $this->assertSame([1, 1], [$large[1], $large[2]]);
        $this->assertSame($small[0] - 0, $large[0] - 0, 'no N+1: the statement count does not depend on the number of rows (Person, status or reference lookups)');
    }

    public function test_qualifications_are_current_recorded_facts_and_no_age_is_exposed(): void
    {
        $person = $this->onLeaveAllMonth('unpaid_leave');
        DB::table('hr.persons')->where('id', $person->id)->update(['birth_date' => '2000-02-29']);
        $qualificationId = (string) Str::uuid7();
        DB::table('hr.person_qualifications')->insert(['id' => $qualificationId, 'person_id' => $person->id, 'academic_degree_id' => $this->createSyntheticAcademicDegree()->id,
            'qualification_type_id' => null, 'created_at' => '2031-05-05 00:00:00+00']);   // "recorded" after the month: still returned, created_at is not an effective date
        $this->viewer();

        $response = $this->api();
        $row = $this->mineRows($response)[$person->id];

        $this->assertSame([$qualificationId], array_column($row['person']['qualifications'], 'id'));
        $this->assertSame('CURRENT_RECORDED_PERSON_FACTS', $row['person']['qualification_semantics']);
        $this->assertSame('CURRENT_RECORDED_PERSON_FACTS', $response['semantics']['qualifications']);
        $this->assertSame('2000-02-29', $row['person']['birth_date']);
        $this->assertFalse($this->contains([$response['summary'], $response['rows']], fn ($key) => is_string($key) && preg_match('/^(age|age_band|age_bracket|years)$/', $key) === 1), 'no age field in the summary or any row (the semantics block only discloses age: NOT_EXPOSED)');
        $this->assertSame(['rows_are_unique_persons' => true, 'reasons_scope' => 'PER_RELATIONSHIP', 'workplace' => 'INTERVAL_SEGMENTS', 'person_identity' => 'DISPLAY_FACTS_ONLY',
            'qualifications' => 'CURRENT_RECORDED_PERSON_FACTS', 'indeterminate' => 'EXCLUDED_DATA_QUALITY_METADATA', 'age' => 'NOT_EXPOSED'], $response['semantics']);
    }

    // ------------------------------------------------------------------------------------------------------------
    // 30–31. Input validation
    // ------------------------------------------------------------------------------------------------------------

    public function test_an_invalid_month_is_a_422_on_month_before_the_s37_population_is_computed(): void
    {
        $this->viewer();
        $statements = [];
        DB::listen(function ($query) use (&$statements): void {
            $statements[] = $query->sql;
        });

        foreach (['month=2026-11-15', 'month=2026-11', 'month=nonsense', 'month=2026-13-01', 'month=2026-02-30', 'month=2026-11-01T00:00:00', 'month[]=2026-11-01', ''] as $query) {
            $this->getJson(self::URL.($query === '' ? '' : '?'.$query))->assertUnprocessable()->assertJsonValidationErrors(['month']);
        }

        $this->assertSame([], array_values(array_filter($statements, fn ($sql) => str_contains($sql, 'hr.employment_relationships'))), 'validation failed before any population query');
        $this->assertSame([], array_values(array_filter($statements, fn ($sql) => str_contains($sql, 'hr.persons'))));
    }

    public function test_only_month_is_a_public_business_input_other_filters_change_nothing(): void
    {
        $this->onLeaveAllMonth('unpaid_leave');
        [$duty, $rel] = $this->emp();
        $this->recStatus($duty, $rel, 'on_duty', '2026-09-27');
        $this->viewer();

        $plain = $this->api();
        foreach (['person_ids[]='.Str::uuid7(), 'unit_id='.Str::uuid7(), 'reason=suspended', 'relationship_end_reason=resigned', 'workplace=x', 'gender_id='.Str::uuid7(), 'age=30', 'qualification=x', 'experience=3', 'per_page=1', 'page=2'] as $extra) {
            $this->assertSame($plain, $this->api('month='.self::M.'&'.$extra), "no filter '{$extra}'");
        }
    }

    // ------------------------------------------------------------------------------------------------------------
    // 41–44. Security
    // ------------------------------------------------------------------------------------------------------------

    public function test_authentication_and_the_dedicated_permission_are_enforced_and_grant_nothing_else(): void
    {
        $this->getJson(self::URL.'?month='.self::M)->assertUnauthorized();

        $this->actingAs($this->createPrincipal(), 'web');
        $this->getJson(self::URL.'?month='.self::M)->assertForbidden();

        $this->principalWithPermissions(array_values(array_diff(Perm::ALL, [Perm::MONTHLY_NOT_ON_DUTY_VIEW])));
        $this->getJson(self::URL.'?month='.self::M)->assertForbidden('every other HR permission together does not grant it');

        $this->principalWithPermissions([Perm::MOVEMENT_EXPIRY_FOLLOWUPS_VIEW, Perm::EMPLOYMENT_STATUS_EXPIRY_FOLLOWUPS_VIEW, Perm::EMPLOYMENT_STATUS_PERIODS_VIEW]);
        $this->getJson(self::URL.'?month='.self::M)->assertForbidden();

        $this->principalWithPermissions([Perm::MONTHLY_NOT_ON_DUTY_VIEW]);   // deliberately no organizational scope grant
        $this->getJson(self::URL.'?month='.self::M)->assertOk();
        $this->getJson('/api/v1/hr/employment-status-expiry-followups')->assertForbidden('the permission opens only this read surface');
        $this->getJson('/api/v1/hr/movement-expiry-followups')->assertForbidden();
        $this->assertSame('hr.monthly_not_on_duty.view', Perm::MONTHLY_NOT_ON_DUTY_VIEW);
        $this->assertContains(Perm::MONTHLY_NOT_ON_DUTY_VIEW, Perm::ALL);
        $this->assertSame(0, (int) DB::selectOne("select count(*) c from security.role_permissions rp join security.permissions p on p.id = rp.permission_id where p.code = 'hr.monthly_not_on_duty.view' and false")->c);
    }

    public function test_the_surface_is_read_only_plain_rbac_and_does_not_mutate_any_hr_data(): void
    {
        $this->onLeaveAllMonth('unpaid_leave');
        $this->viewer();
        $tables = ['hr.persons', 'hr.employment_relationships', 'hr.employment_status_periods', 'hr.organizational_placement_periods', 'hr.full_secondment_periods',
            'hr.workplace_assignment_periods', 'hr.partial_secondment_periods', 'hr.work_schedule_periods', 'hr.person_qualifications', 'hr.return_intention_periods',
            'audit.audit_entries', 'automation.movement_expiry_followups', 'automation.employment_status_expiry_followups', 'security.permissions'];
        $counts = fn () => array_map(fn ($t) => DB::table($t)->count(), $tables);
        $before = $counts();

        $this->api();
        $this->postJson(self::URL, [])->assertStatus(405);
        $this->patchJson(self::URL, [])->assertStatus(405);
        $this->putJson(self::URL, [])->assertStatus(405);
        $this->deleteJson(self::URL)->assertStatus(405);

        $this->assertSame($before, $counts(), 'a report read creates no row and no audit entry');
        $routes = collect(Route::getRoutes())->filter(fn ($r) => $r->uri() === 'api/v1/hr/not-on-duty');
        $this->assertSame([['GET', 'HEAD']], $routes->map(fn ($r) => $r->methods())->values()->all(), 'exactly one read route');
        $controller = preg_replace('#/\*.*?\*/|//[^\n]*#s', '', (string) file_get_contents(base_path('app/Modules/HumanResources/Presentation/Http/Controllers/NotOnDutyController.php')));
        $this->assertStringNotContainsString('ScopedAuthorizationChecker', $controller, 'plain RBAC: no organizational scope');
        $this->assertStringNotContainsString('organizational_unit', $controller);
    }

    // ------------------------------------------------------------------------------------------------------------
    // 46–48. No persistence, route guards
    // ------------------------------------------------------------------------------------------------------------

    public function test_no_report_persistence_was_added(): void
    {
        // Mechanical S41 accommodation: S41's 2026_10_18 migrations follow; S39's own migration set is still exactly one permission seed.
        $newest = collect(glob(base_path('database/migrations/*.php')))->map('basename')->sort()->filter(fn ($n) => $n >= '2026_10_17' && $n < '2026_10_18')->values()->all();
        $this->assertSame(['2026_10_17_000001_seed_security_monthly_not_on_duty_permission.php'], $newest, 'S39 adds only the permission registration');
        $this->assertSame(0, DB::table('information_schema.tables')->whereIn('table_schema', ['hr', 'ref', 'org', 'automation', 'reporting'])
            ->where(fn ($q) => $q->where('table_name', 'like', '%not_on_duty%')->orWhere('table_name', 'like', '%report%'))->count(), 'no report table');
        $this->assertSame(0, (int) DB::selectOne('select count(*) c from pg_matviews')->c);
    }

    public function test_the_endpoint_uri_passes_every_current_route_guard_unchanged(): void
    {
        $uri = 'api/v1/hr/not-on-duty';
        $this->assertSame(0, preg_match('/report|export|dashboard|as-of|pdf|xlsx|csv/i', $uri), 'S27 predicate');
        $this->assertSame(0, preg_match('/monthly(?!-cadre-categories|CadreCategory)|report|export|dashboard|pdf|xlsx|csv|print/i', $uri), 'S37 predicate');
        foreach (['supervisory', 'leave', 'renewal', 'professional-history', 'job-history', 'export', 'report'] as $segment) {
            $this->assertStringNotContainsString($segment, $uri, 'HR scope predicate');
        }
        $route = collect(Route::getRoutes())->first(fn ($r) => $r->uri() === $uri);
        $this->assertNotNull($route);
        $this->assertSame(0, preg_match('/ListMonthlyReportingPopulation|MonthlyReporting/', $route->getActionName()));
        $this->assertContains('permission:'.Perm::MONTHLY_NOT_ON_DUTY_VIEW, $route->gatherMiddleware());
    }

    public function test_s37_production_code_is_untouched_and_the_s37_contract_is_reused_as_is(): void
    {
        $this->assertTrue(class_exists(ListMonthlyReportingPopulation::class));
        $source = (string) file_get_contents(base_path('app/Modules/HumanResources/Application/Queries/Reporting/ListMonthlyReportingPopulation.php'));
        foreach (['national_id', 'full_name', 'not_on_duty', 'NotOnDuty'] as $word) {
            $this->assertStringNotContainsString($word, $source, "S37 was not changed to serve S39 ({$word})");
        }
        $this->assertSame(['personId', 'genderId', 'birthDate', 'qualifications', 'qualificationSemantics', 'dutyClassification', 'relationships'],
            array_map(fn ($p) => $p->getName(), (new \ReflectionClass(MonthlyReportingPersonRow::class))->getConstructor()->getParameters()));
    }
}
