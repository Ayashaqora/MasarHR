<?php

namespace Tests\Feature\HumanResources;

use App\Modules\HumanResources\Application\Commands\EndEmploymentRelationship;
use App\Modules\HumanResources\Application\Commands\RecordEmploymentJobTitlePeriod;
use App\Modules\HumanResources\Application\Commands\RecordPartialSecondmentPeriod;
use App\Modules\HumanResources\Application\Commands\RecordWorkSchedulePeriod;
use App\Modules\HumanResources\Application\Commands\StartFullSecondment;
use App\Modules\HumanResources\Application\Commands\StartWorkplaceAssignment;
use App\Modules\HumanResources\Application\Commands\TransferEmployee;
use App\Modules\HumanResources\Application\Queries\Reporting\AdministrativeReportPersonRecord;
use App\Modules\HumanResources\Application\Queries\Reporting\AdministrativeReportResult;
use App\Modules\HumanResources\Application\Queries\Reporting\AdministrativeSections;
use App\Modules\HumanResources\Application\Queries\Reporting\BuildAdministrativeReportResult;
use App\Modules\HumanResources\Application\Queries\Reporting\ListMonthlyReportingPopulation;
use App\Modules\HumanResources\Application\Queries\Reporting\ListMonthlyWorkforceDimensions;
use App\Modules\HumanResources\Domain\Exceptions\InconsistentDimensionHistoryException;
use App\Modules\HumanResources\Domain\OrganizationalHierarchyPaths;
use App\Modules\HumanResources\Domain\PartialSecondmentOccurrences;
use App\Modules\HumanResources\Infrastructure\Authorization\HumanResourcesPermissionCatalog as Perm;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\EmploymentRelationship;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\Person;
use App\Modules\Reference\Application\Commands\DefineJobTitleAdministratorClassificationPeriod;
use App\Modules\Reference\Infrastructure\Persistence\Eloquent\JobTitle;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/**
 * S42 R2 Administrative / Job Title / Gender / Actual Work report (docs/administrative-report-foundation-specification.md): the
 * HAS_ON_DUTY Persons of the canonical S37 population, enriched by S40 and the R2-specific batches, as ONE canonical dataset from which
 * every official section derives. Real PostgreSQL, synthetic data only. Report month: November 2026 [2026-11-01, 2026-12-01).
 * Assertions about fixtures target the Persons this test creates; the reconciliation and consistency checks hold for the WHOLE result.
 */
class AdministrativeReportFoundationTest extends HumanResourcesTestCase
{
    private const M = '2026-11-01';

    private const N = '2026-12-01';

    private const URL = '/api/v1/hr/administrative-report';

    private const BUILDER = 'app/Modules/HumanResources/Application/Queries/Reporting/BuildAdministrativeReportResult.php';

    private const SUN_THU = ['SUNDAY', 'MONDAY', 'TUESDAY', 'WEDNESDAY', 'THURSDAY'];

    // ------------------------------------------------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------------------------------------------------

    private function report(string $month = self::M): AdministrativeReportResult
    {
        return app(BuildAdministrativeReportResult::class)($month);
    }

    /** A HAS_ON_DUTY employee: the relationship plus an explicit on_duty status covering its active days of the month. */
    private function emp(string $type = 'permanent', string $from = '2026-01-01', ?Person $person = null): array
    {
        $person ??= $this->createPersonRecord();
        $rel = $this->createEmploymentRelationship($person, $type, null, $from);
        $this->rawStatus($rel, 'on_duty', max($from, '2026-10-01'));

        return [$person, $rel];
    }

    private function rawStatus(EmploymentRelationship $rel, string $code, string $from, ?string $to = null): void
    {
        DB::table('hr.employment_status_periods')->insert([
            'id' => (string) Str::uuid7(), 'employment_relationship_id' => $rel->id, 'status_detail_id' => $this->statusDetail($code)->id,
            'effective_from' => $from, 'effective_to' => $to, 'created_at' => now(),
        ]);
    }

    private function record(Person $person, string $month = self::M): AdministrativeReportPersonRecord
    {
        $rows = array_values(array_filter($this->report($month)->rows, fn (AdministrativeReportPersonRecord $row) => $row->personId === $person->id));
        $this->assertCount(1, $rows, 'exactly one canonical record per Person');

        return $rows[0];
    }

    private function recordOrNull(Person $person, string $month = self::M): ?AdministrativeReportPersonRecord
    {
        foreach ($this->report($month)->rows as $row) {
            if ($row->personId === $person->id) {
                return $row;
            }
        }

        return null;
    }

    private function relOf(Person $person, int $i = 0): array
    {
        return $this->record($person)->relationships[$i];
    }

    /** @return list<array{0: string, 1: string, 2: string}> */
    private function shape(array $segments, string $stateKey = 'state'): array
    {
        return array_map(fn (array $s) => [$s['from'], $s['to'], $s[$stateKey]], $segments);
    }

    private function title(EmploymentRelationship $rel, string $from, ?JobTitle $title = null): JobTitle
    {
        $title ??= $this->createSyntheticJobTitle();
        app(RecordEmploymentJobTitlePeriod::class)->handle($rel->refresh(), $title, $from);

        return $title;
    }

    private function section(AdministrativeReportResult $r, string $key, string $bucketKey, string $bucket, string $countKey = 'person_count'): int
    {
        foreach ($r->sections[$key] as $row) {
            if ($row[$bucketKey] === $bucket) {
                return $row[$countKey];
            }
        }
        $this->fail("bucket {$bucket} not found in {$key}");
    }

    private function dq(AdministrativeReportResult $r, string $code): array
    {
        foreach ($r->sections['data_quality'] as $entry) {
            if ($entry['code'] === $code) {
                return $entry;
            }
        }
        $this->fail("data-quality code {$code} missing");
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
    // 1. Population
    // ------------------------------------------------------------------------------------------------------------

    public function test_has_on_duty_persons_are_in_the_population_no_on_duty_are_excluded_and_indeterminate_are_excluded_with_data_quality(): void
    {
        [$onDuty] = $this->emp();
        $noOnDuty = $this->createPersonRecord();
        $rel = $this->createEmploymentRelationship($noOnDuty, 'permanent', null, '2026-01-01');
        $this->rawStatus($rel, 'unpaid_leave', '2026-10-01', '2026-12-30');
        $indeterminate = $this->createPersonRecord();
        $this->createEmploymentRelationship($indeterminate, 'permanent', null, '2026-01-01'); // no status at all

        $r = $this->report();

        $this->assertNotNull($this->recordOrNull($onDuty), 'HAS_ON_DUTY is in the R2 population');
        $this->assertNull($this->recordOrNull($noOnDuty), 'NO_ON_DUTY is excluded');
        $this->assertNull($this->recordOrNull($indeterminate), 'INDETERMINATE is excluded from the population');
        $this->assertContains($indeterminate->id, array_column($this->dq($r, 'INDETERMINATE_DUTY_STATE')['persons'], 'person_id'), 'and exposed as data quality, never guessed');
        $this->assertNotContains($noOnDuty->id, array_column($this->dq($r, 'INDETERMINATE_DUTY_STATE')['persons'], 'person_id'));
        $this->assertNotContains($indeterminate->id, array_map(fn ($row) => $row->personId, $r->rows));
        $this->assertGreaterThanOrEqual(1, $r->population['no_on_duty_excluded']);
        $this->assertGreaterThanOrEqual(1, $r->population['indeterminate_excluded']);
    }

    public function test_the_indeterminate_data_quality_entry_carries_the_affected_person_identity_and_count(): void
    {
        $person = $this->createPersonRecord();
        $this->createEmploymentRelationship($person, 'permanent', null, '2026-01-01');

        $entry = $this->dq($this->report(), 'INDETERMINATE_DUTY_STATE');

        $this->assertSame($entry['person_count'], count($entry['persons']));
        $match = array_values(array_filter($entry['persons'], fn ($p) => $p['person_id'] === $person->id));
        $this->assertSame([$person->national_id, 'موظف اختبار'], [$match[0]['national_id'], $match[0]['full_name_ar']]);
    }

    public function test_the_overall_headcount_is_the_distinct_person_count_even_with_several_relationships(): void
    {
        [$person, $old] = $this->emp('contract', '2026-01-01');
        $this->end($person, $old, '2026-11-10');
        $new = $this->createEmploymentRelationship($person, 'contract', null, '2026-11-10');
        $this->rawStatus($new, 'on_duty', '2026-11-10');
        $before = $this->report()->overallHeadcount;

        $record = $this->record($person);

        $this->assertCount(2, $record->relationships, 'both relationships preserved on the one record');
        $ids = array_map(fn ($row) => $row->personId, $this->report()->rows);
        $this->assertSame($ids, array_values(array_unique($ids)), 'distinct Persons');
        $this->assertSame(count($ids), $this->report()->overallHeadcount);
        $this->assertSame($before, $this->report()->overallHeadcount);
    }

    private function end(Person $person, EmploymentRelationship $rel, string $to): void
    {
        $rel->refresh();
        app(EndEmploymentRelationship::class)->handle($person, $rel, $rel->version, $to, false);
    }

    // ------------------------------------------------------------------------------------------------------------
    // 2–4. Organizational placement vs actual workplace
    // ------------------------------------------------------------------------------------------------------------

    public function test_organizational_placement_and_actual_workplace_are_separate_and_a_full_secondment_does_not_rewrite_the_placement(): void
    {
        [$person, $rel] = $this->emp();
        $home = $this->createUnit('الوحدة الأصلية');
        $destination = $this->createUnit('وجهة الإعارة');
        $this->recordPlacement($rel, $home, '2026-02-01');
        app(StartFullSecondment::class)->handle($rel->refresh(), $destination, '2026-11-10');

        $relationship = $this->relOf($person);

        $this->assertSame([[self::M, self::N, 'RESOLVED']], $this->shape($relationship['organizational_placement_segments']), 'the placement is one unbroken segment');
        $this->assertSame($home->id, $relationship['organizational_placement_segments'][0]['organizational_unit_id'], 'still the original unit');
        $workplaces = $relationship['actual_workplace_segments'];
        $this->assertSame([[self::M, '2026-11-10', 'RESOLVED'], ['2026-11-10', self::N, 'RESOLVED']], $this->shape($workplaces));
        $this->assertSame([[$home->id, 'PLACEMENT'], [$destination->id, 'FULL_SECONDMENT']], array_map(fn ($s) => [$s['organizational_unit_id'], $s['movement_type']], $workplaces));
    }

    public function test_a_transfer_in_the_month_changes_the_organizational_placement_prospectively_and_preserves_both_segments(): void
    {
        [$person, $rel] = $this->emp();
        $from = $this->createUnit('قبل النقل');
        $to = $this->createUnit('بعد النقل');
        $this->recordPlacement($rel, $from, '2026-02-01');
        app(TransferEmployee::class)->handle($rel->refresh(), $to, '2026-11-15', $this->transferDecisionType());

        $relationship = $this->relOf($person);

        $this->assertSame([[self::M, '2026-11-15', 'RESOLVED'], ['2026-11-15', self::N, 'RESOLVED']], $this->shape($relationship['organizational_placement_segments']));
        $this->assertSame([$from->id, $to->id], array_map(fn ($s) => $s['organizational_unit_id'], $relationship['organizational_placement_segments']), 'never collapsed to the last value of the month');
        $this->assertSame(['PLACEMENT', 'PLACEMENT'], array_map(fn ($s) => $s['movement_type'], $relationship['actual_workplace_segments']));
        $r = $this->report();
        $this->assertSame(1, $this->unitDirect($r, $from->id), 'the Person is in both placement buckets');
        $this->assertSame(1, $this->unitDirect($r, $to->id));
    }

    public function test_a_workplace_assignment_changes_the_actual_workplace_but_neither_the_placement_nor_the_job_title(): void
    {
        [$person, $rel] = $this->emp();
        $home = $this->createUnit('أصل');
        $assigned = $this->createUnit('تكليف');
        $this->recordPlacement($rel, $home, '2026-02-01');
        $title = $this->title($rel, '2026-02-01');
        app(StartWorkplaceAssignment::class)->handle($rel->refresh(), $assigned, '2026-11-12', $this->assignmentDecisionType());

        $relationship = $this->relOf($person);

        $this->assertSame([[self::M, self::N, 'RESOLVED']], $this->shape($relationship['organizational_placement_segments']));
        $this->assertSame($home->id, $relationship['organizational_placement_segments'][0]['organizational_unit_id']);
        $this->assertSame(['PLACEMENT', 'WORKPLACE_ASSIGNMENT'], array_map(fn ($s) => $s['movement_type'], $relationship['actual_workplace_segments']));
        $this->assertSame([[self::M, self::N, 'RESOLVED']], $this->shape($relationship['job_title_segments']), 'the official job title is untouched');
        $this->assertSame($title->id, $relationship['job_title_segments'][0]['job_title']['id']);
    }

    private function unitDirect(AdministrativeReportResult $r, string $unitId): int
    {
        foreach ($r->sections['organizational_placement']['units'] as $unit) {
            if ($unit['unit_id'] === $unitId) {
                return $unit['direct_person_count'];
            }
        }
        $this->fail('unit not in the placement section');
    }

    // ------------------------------------------------------------------------------------------------------------
    // 5. Partial secondment weekdays (allocation, not attendance)
    // ------------------------------------------------------------------------------------------------------------

    public function test_a_partial_secondment_preserves_its_destination_weekdays_and_the_underlying_workplace_without_counts(): void
    {
        [$person, $rel] = $this->emp();
        $home = $this->createUnit('الأصل');
        $dest = $this->createUnit('الوجهة الجزئية');
        $this->recordPlacement($rel, $home, '2026-02-01');
        app(RecordWorkSchedulePeriod::class)->handle($rel, '2026-01-01', self::SUN_THU);
        app(RecordPartialSecondmentPeriod::class)->handle($rel->refresh(), $dest, '2026-11-10', '2026-11-24', ['SUNDAY', 'TUESDAY']);

        $segments = $this->relOf($person)['actual_workplace_segments'];

        $partial = array_values(array_filter($segments, fn ($s) => $s['movement_type'] === 'PARTIAL_SECONDMENT'));
        $this->assertCount(1, $partial);
        // weekday codes come back in ISO order (Monday first), exactly as S30/S37 store them
        $this->assertSame([$dest->id, '2026-11-10', '2026-11-24', ['TUESDAY', 'SUNDAY'], ['TUESDAY', 'SUNDAY']], [$partial[0]['organizational_unit_id'], $partial[0]['from'], $partial[0]['to'], $partial[0]['weekdays'], $partial[0]['scheduled_weekdays_in_period']]);
        $underlying = array_values(array_filter($segments, fn ($s) => ($s['underlying_of_partial_allocation'] ?? false) === true));
        $this->assertSame($home->id, $underlying[0]['organizational_unit_id'], 'R2-D42: the base placement stays represented as the underlying PLACEMENT');
        $this->assertSame('PLACEMENT', $underlying[0]['movement_type']);
        $this->assertNull($underlying[0]['weekdays'], 'R2-D42: the underlying placement carries no weekday allocation — no residual weekdays are inferred');
        $this->assertArrayNotHasKey('scheduled_weekdays_in_period', $underlying[0]);
        $this->assertArrayNotHasKey('underlying_of_partial_allocation', $partial[0], 'destinations are never flagged as the underlying placement: the two stay distinguishable');
        $this->assertSame(['TUESDAY', 'SUNDAY'], $partial[0]['weekdays'], 'the configured weekdays are the only explicit weekday allocations');
        $json = json_encode($segments);
        foreach (['percent', 'fte', 'worked', 'attendance', 'days_count', 'occurrences'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, strtolower($json), "weekdays are allocation only: no {$forbidden}");
        }
    }

    public function test_a_partial_secondment_destination_with_zero_applicable_weekdays_creates_no_workplace_occurrence(): void
    {
        [$person, $rel] = $this->emp();
        $home = $this->createUnit('الأصل');
        $dest = $this->createUnit('وجهة بلا أيام');
        $this->recordPlacement($rel, $home, '2026-02-01');
        app(RecordWorkSchedulePeriod::class)->handle($rel, '2026-01-01', self::SUN_THU);
        // 2026-11-10 is a Tuesday and 2026-11-11 a Wednesday: the configured SUNDAY never occurs in [11-10, 11-12).
        app(RecordPartialSecondmentPeriod::class)->handle($rel->refresh(), $dest, '2026-11-10', '2026-11-12', ['SUNDAY']);

        $segments = $this->relOf($person)['actual_workplace_segments'];

        $this->assertSame([], array_values(array_filter($segments, fn ($s) => $s['organizational_unit_id'] === $dest->id)), 'no occurrence for a destination without an applicable weekday');
        $r = $this->report();
        $workplaceIds = array_column($r->sections['actual_workplaces']['workplaces'], 'unit_id');
        $this->assertNotContains($dest->id, $workplaceIds, 'and no bucket');
        $this->assertContains($home->id, $workplaceIds);
    }

    public function test_applicable_weekdays_are_evaluated_inside_report_movement_and_weekdays_on_half_open_dates(): void
    {
        $w = fn (string $from, string $to, string $mFrom, ?string $mTo, array $days) => PartialSecondmentOccurrences::applicableWeekdays($from, $to, $mFrom, $mTo, $days);

        $this->assertSame(['SUNDAY'], $w(self::M, self::N, '2026-11-01', '2026-11-02', ['SUNDAY']), 'one Sunday: [11-01, 11-02)');
        $this->assertSame([], $w(self::M, self::N, '2026-11-02', '2026-11-08', ['SUNDAY']), 'the movement ends the day before the next Sunday (11-08 is excluded)');
        $this->assertSame(['SUNDAY'], $w(self::M, self::N, '2026-11-02', '2026-11-09', ['SUNDAY']), '[11-02, 11-09) contains Sunday 11-08');
        $this->assertSame([], $w(self::M, self::N, '2026-10-01', '2026-11-01', ['SUNDAY', 'MONDAY']), 'a movement ending exactly on month_start does not touch the month');
        $this->assertSame([], $w(self::M, self::N, '2026-12-01', null, ['SUNDAY']), 'a movement starting exactly on next_month_start is outside');
        $this->assertSame(['SUNDAY', 'THURSDAY'], $w(self::M, self::N, '2026-11-26', null, ['SUNDAY', 'THURSDAY']), 'configured order preserved: Thursday 11-26 and Sunday 11-29');
        $this->assertSame([], $w(self::M, self::N, '2026-11-10', '2026-11-12', []), 'no configured weekday');
    }

    // ------------------------------------------------------------------------------------------------------------
    // 6–7. Multiple workplaces and multiple job titles — distinct Person per bucket, non-reconciling sums
    // ------------------------------------------------------------------------------------------------------------

    public function test_a_person_with_two_actual_workplaces_is_one_headcount_and_appears_in_both_buckets_once(): void
    {
        [$person, $rel] = $this->emp();
        $home = $this->createUnit('م1');
        $dest = $this->createUnit('م2');
        $this->recordPlacement($rel, $home, '2026-02-01');
        app(StartFullSecondment::class)->handle($rel->refresh(), $dest, '2026-11-10');
        $before = $this->report();

        $this->assertNotNull($this->recordOrNull($person));
        $buckets = [];
        foreach ($before->sections['actual_workplaces']['workplaces'] as $w) {
            $buckets[$w['unit_id']] = $w['person_count'];
        }
        $this->assertSame([1, 1], [$buckets[$home->id], $buckets[$dest->id]], 'distinct Person within each bucket');
        $this->assertSame(1, count(array_filter($before->rows, fn ($row) => $row->personId === $person->id)), 'headcount still one');
    }

    public function test_every_job_title_segment_of_the_month_is_preserved_and_the_person_occurs_in_each_title_bucket_once(): void
    {
        [$person, $rel] = $this->emp();
        $a = $this->title($rel, '2026-02-01');
        $b = $this->title($rel, '2026-11-15');

        $segments = $this->relOf($person)['job_title_segments'];
        $this->assertSame([[self::M, '2026-11-15', 'RESOLVED'], ['2026-11-15', self::N, 'RESOLVED']], $this->shape($segments));
        $this->assertSame([$a->id, $b->id], array_map(fn ($s) => $s['job_title']['id'], $segments));

        $r = $this->report();
        $counts = [];
        foreach ($r->sections['job_titles']['titles'] as $t) {
            $counts[$t['job_title']['id']] = $t['person_count'];
        }
        $this->assertSame([1, 1], [$counts[$a->id], $counts[$b->id]]);
        $this->assertSame(1, count(array_filter($r->rows, fn ($row) => $row->personId === $person->id)));
    }

    public function test_multi_value_bucket_sums_validly_exceed_the_overall_headcount_and_nothing_forces_them_to_reconcile(): void
    {
        $before = $this->report();
        $titleSumBefore = array_sum(array_column($before->sections['job_titles']['titles'], 'person_count'));
        $workplaceSumBefore = array_sum(array_column($before->sections['actual_workplaces']['workplaces'], 'person_count'));

        [$person, $rel] = $this->emp();
        $this->title($rel, '2026-02-01');
        $this->title($rel, '2026-11-15');
        $home = $this->createUnit('م');
        $dest = $this->createUnit('ن');
        $this->recordPlacement($rel, $home, '2026-02-01');
        app(StartFullSecondment::class)->handle($rel->refresh(), $dest, '2026-11-10');
        $after = $this->report();

        $this->assertSame($before->overallHeadcount + 1, $after->overallHeadcount, 'one more distinct Person');
        $this->assertSame($titleSumBefore + 2, array_sum(array_column($after->sections['job_titles']['titles'], 'person_count')), 'the same Person is in two title buckets');
        $this->assertSame($workplaceSumBefore + 2, array_sum(array_column($after->sections['actual_workplaces']['workplaces'], 'person_count')), 'and in two workplace buckets');
        $this->assertStringNotContainsString('percent', strtolower(json_encode($after->sections)), 'no percentage is invented to force reconciliation');
        $this->assertNotNull($this->recordOrNull($person));
    }

    public function test_a_person_with_the_same_title_in_two_relationships_counts_once_in_that_bucket(): void
    {
        [$person, $old] = $this->emp('contract', '2026-01-01');
        $title = $this->title($old, '2026-02-01');
        $this->end($person, $old, '2026-11-10');
        $new = $this->createEmploymentRelationship($person, 'contract', null, '2026-11-10');
        $this->rawStatus($new, 'on_duty', '2026-11-10');
        $this->title($new, '2026-11-10', $title);

        $counts = [];
        foreach ($this->report()->sections['job_titles']['titles'] as $t) {
            $counts[$t['job_title']['id']] = $t['person_count'];
        }

        $this->assertSame(1, $counts[$title->id], 'DISTINCT person_id within the bucket');
    }

    // ------------------------------------------------------------------------------------------------------------
    // 8. Supervisory independence
    // ------------------------------------------------------------------------------------------------------------

    public function test_the_official_job_title_is_independent_of_any_supervisory_assignment(): void
    {
        $tables = DB::table('information_schema.tables')->where('table_schema', 'hr')->pluck('table_name')->all();
        foreach ($tables as $table) {
            $this->assertStringNotContainsString('supervis', $table, 'no supervisory assignment exists to overwrite a job title');
        }
        $code = preg_replace('#/\*.*?\*/|//[^\n]*#s', '', (string) file_get_contents(base_path(self::BUILDER)));
        $this->assertStringNotContainsString('supervis', strtolower($code), 'R2 reads no supervisory data: it cannot overwrite a title, create history, imply promotion or change the administrator mapping');

        [$person, $rel] = $this->emp();
        $title = $this->title($rel, '2026-02-01');
        $this->assertSame($title->id, $this->relOf($person)['job_title_segments'][0]['job_title']['id']);
        $this->assertSame(1, (int) DB::table('hr.employment_job_title_periods')->where('employment_relationship_id', $rel->id)->count(), 'no fabricated job-title history');
    }

    // ------------------------------------------------------------------------------------------------------------
    // 9–10. Administrator classification (S40 temporal mapping)
    // ------------------------------------------------------------------------------------------------------------

    public function test_the_administrator_classification_is_the_as_of_mapping_resolved_unmapped_or_not_recorded_and_never_inferred(): void
    {
        [$admin, $a] = $this->emp();
        $adminTitle = $this->title($a, '2026-02-01');
        app(DefineJobTitleAdministratorClassificationPeriod::class)->handle($adminTitle, true, '2026-01-01', null);
        [$staff, $b] = $this->emp();
        $staffTitle = $this->title($b, '2026-02-01');
        app(DefineJobTitleAdministratorClassificationPeriod::class)->handle($staffTitle, false, '2026-01-01', null);
        [$unmapped, $c] = $this->emp();
        $this->title($c, '2026-02-01'); // a recorded title with no mapping
        [$noTitle] = $this->emp();

        $this->assertSame('ADMINISTRATOR', $this->relOf($admin)['job_title_segments'][0]['administrator_classification']);
        $this->assertSame('NOT_ADMINISTRATOR', $this->relOf($staff)['job_title_segments'][0]['administrator_classification'], 'false is a resolved classification');
        $this->assertSame('UNMAPPED', $this->relOf($unmapped)['job_title_segments'][0]['administrator_classification'], 'UNMAPPED never becomes NO');
        $this->assertSame('NOT_RECORDED', $this->relOf($noTitle)['job_title_segments'][0]['administrator_classification']);
        $this->assertSame('NOT_RECORDED', $this->relOf($noTitle)['job_title_segments'][0]['state']);

        $r = $this->report();
        $this->assertContains($unmapped->id, array_column($this->dq($r, 'ADMINISTRATOR_MAPPING_UNMAPPED')['persons'], 'person_id'));
        $this->assertNotContains($staff->id, array_column($this->dq($r, 'ADMINISTRATOR_MAPPING_UNMAPPED')['persons'], 'person_id'));
        $this->assertContains($noTitle->id, array_column($this->dq($r, 'JOB_TITLE_NOT_RECORDED')['persons'], 'person_id'));
        $this->assertNotContains($unmapped->id, array_column($this->dq($r, 'JOB_TITLE_NOT_RECORDED')['persons'], 'person_id'), 'UNMAPPED and NOT_RECORDED stay distinct');
    }

    public function test_an_administrator_classification_changing_mid_month_preserves_both_segments_and_both_buckets(): void
    {
        [$person, $rel] = $this->emp();
        $title = $this->title($rel, '2026-02-01');
        app(DefineJobTitleAdministratorClassificationPeriod::class)->handle($title, false, '2026-01-01', '2026-11-15');
        app(DefineJobTitleAdministratorClassificationPeriod::class)->handle($title, true, '2026-11-15', null);

        $segments = $this->relOf($person)['job_title_segments'];

        $this->assertSame([[self::M, '2026-11-15', 'RESOLVED'], ['2026-11-15', self::N, 'RESOLVED']], $this->shape($segments));
        $this->assertSame(['NOT_ADMINISTRATOR', 'ADMINISTRATOR'], array_map(fn ($s) => $s['administrator_classification'], $segments), 'not collapsed to a single monthly value');
        $r = $this->report();
        $this->assertGreaterThanOrEqual(1, $this->section($r, 'administrator_classification', 'classification', 'ADMINISTRATOR'));
        $this->assertGreaterThanOrEqual(1, $this->section($r, 'administrator_classification', 'classification', 'NOT_ADMINISTRATOR'));
    }

    // ------------------------------------------------------------------------------------------------------------
    // 11. Gender
    // ------------------------------------------------------------------------------------------------------------

    public function test_gender_buckets_are_current_recorded_and_reconcile_to_the_overall_headcount(): void
    {
        [$male] = $this->emp();
        [$female] = $this->emp();
        [$none] = $this->emp();
        DB::table('hr.persons')->where('id', $female->id)->update(['gender_id' => $this->gender('female')->id]);
        DB::table('hr.persons')->where('id', $none->id)->update(['gender_id' => null]);

        $this->assertSame('MALE', $this->record($male)->gender['code']);
        $this->assertSame('FEMALE', $this->record($female)->gender['code']);
        $this->assertSame(['NOT_RECORDED', null], [$this->record($none)->gender['state'], $this->record($none)->gender['code']]);

        $r = $this->report();
        $this->assertSame(['MALE', 'FEMALE', 'NOT_RECORDED'], array_column($r->sections['gender'], 'gender'));
        $this->assertSame($r->overallHeadcount, array_sum(array_column($r->sections['gender'], 'person_count')), 'MALE + FEMALE + NOT_RECORDED = overall R2 headcount');
        foreach ($r->sections['gender'] as $bucket) {
            $expected = count(array_filter($r->rows, fn ($row) => ($row->gender['state'] === 'RECORDED' ? $row->gender['code'] : 'NOT_RECORDED') === $bucket['gender']));
            $this->assertSame($expected, $bucket['person_count'], "the {$bucket['gender']} bucket equals the canonical rows that match it");
        }
        $this->assertGreaterThanOrEqual(1, $this->section($r, 'gender', 'gender', 'NOT_RECORDED'));
        $this->assertContains($none->id, array_column($this->dq($r, 'GENDER_NOT_RECORDED')['persons'], 'person_id'));
        $this->assertNotContains($male->id, array_column($this->dq($r, 'GENDER_NOT_RECORDED')['persons'], 'person_id'));
    }

    // ------------------------------------------------------------------------------------------------------------
    // 12. Hierarchy drill-down, boundaries, data quality
    // ------------------------------------------------------------------------------------------------------------

    public function test_the_organizational_hierarchy_is_preserved_with_distinct_person_counts_per_node_and_subtree(): void
    {
        $root = $this->createUnit('الجذر');
        $child = $this->createUnit('الإدارة', $root->id);
        $grand = $this->createUnit('القسم', $child->id);
        [$a, $relA] = $this->emp();
        $this->recordPlacement($relA, $grand, '2026-02-01');
        [$b, $relB] = $this->emp();
        $this->recordPlacement($relB, $child, '2026-02-01');

        $units = [];
        foreach ($this->report()->sections['organizational_placement']['units'] as $u) {
            $units[$u['unit_id']] = $u;
        }

        $this->assertSame([$root->id, $child->id, $grand->id], $units[$grand->id]['path'], 'full path root → unit');
        $this->assertSame([0, 1, 2], [$units[$root->id]['depth'], $units[$child->id]['depth'], $units[$grand->id]['depth']]);
        $this->assertSame([0, 1, 1], [$units[$root->id]['direct_person_count'], $units[$child->id]['direct_person_count'], $units[$grand->id]['direct_person_count']]);
        $this->assertSame([2, 2, 1], [$units[$root->id]['subtree_person_count'], $units[$child->id]['subtree_person_count'], $units[$grand->id]['subtree_person_count']], 'subtrees count distinct Persons');
        $this->assertContains($grand->id, array_column($this->relOf($a)['organizational_placement_segments'], 'organizational_unit_id'));
    }

    public function test_a_missing_placement_is_organizational_placement_not_recorded_and_a_missing_workplace_is_not_determinable(): void
    {
        [$person] = $this->emp(); // no placement, no movement

        $record = $this->record($person);

        $this->assertSame('NOT_RECORDED', $this->relOf($person)['organizational_placement_segments'][0]['state']);
        $this->assertSame('UNRESOLVED', $this->relOf($person)['actual_workplace_segments'][0]['state']);
        $this->assertContains('ORGANIZATIONAL_PLACEMENT_NOT_RECORDED', $record->dataQuality);
        $this->assertContains('ACTUAL_WORKPLACE_NOT_DETERMINABLE', $record->dataQuality);
        $r = $this->report();
        $this->assertContains($person->id, array_column($this->dq($r, 'ORGANIZATIONAL_PLACEMENT_NOT_RECORDED')['persons'], 'person_id'));
        $this->assertContains($person->id, array_column($this->dq($r, 'ACTUAL_WORKPLACE_NOT_DETERMINABLE')['persons'], 'person_id'));
        foreach ($r->sections['data_quality'] as $entry) {
            $this->assertSame($entry['person_count'], count($entry['persons']), 'every data-quality result has a count and an affected-Person drilldown');
        }
        $this->assertSame(AdministrativeReportResult::DATA_QUALITY_CODES, array_column($r->sections['data_quality'], 'code'));
    }

    public function test_an_ambiguous_movement_state_is_not_determinable_and_never_guessed(): void
    {
        [$person, $rel] = $this->emp();
        $home = $this->createUnit('أ');
        $this->recordPlacement($rel, $home, '2026-02-01');
        $a = $this->createUnit('ب');
        $b = $this->createUnit('ج');
        DB::table('hr.full_secondment_periods')->insert(['id' => (string) Str::uuid7(), 'employment_relationship_id' => $rel->id, 'organizational_unit_id' => $a->id, 'effective_from' => '2026-11-01', 'effective_to' => null, 'created_at' => now()]);
        DB::table('hr.workplace_assignment_periods')->insert(['id' => (string) Str::uuid7(), 'employment_relationship_id' => $rel->id, 'organizational_unit_id' => $b->id, 'effective_from' => '2026-11-01', 'effective_to' => null, 'created_at' => now()]);

        $segment = $this->relOf($person)['actual_workplace_segments'][0];

        $this->assertSame('AMBIGUOUS_MOVEMENT_STATE', $segment['state']);
        $this->assertNull($segment['organizational_unit_id'], 'no winner is picked');
        $this->assertCount(2, $segment['competing_movements']);
        $this->assertContains('ACTUAL_WORKPLACE_NOT_DETERMINABLE', $this->record($person)->dataQuality);
    }

    public function test_dates_are_half_open_a_title_ending_on_month_start_and_a_placement_starting_on_the_next_month_are_outside(): void
    {
        [$person, $rel] = $this->emp();
        $title = $this->createSyntheticJobTitle();
        DB::table('hr.employment_job_title_periods')->insert(['id' => (string) Str::uuid7(), 'employment_relationship_id' => $rel->id, 'job_title_id' => $title->id, 'effective_from' => '2026-01-01', 'effective_to' => self::M, 'start_knowledge_state' => 'KNOWN', 'created_at' => now()]);
        $unit = $this->createUnit('لاحقة');
        DB::table('hr.organizational_placement_periods')->insert(['id' => (string) Str::uuid7(), 'employment_relationship_id' => $rel->id, 'organizational_unit_id' => $unit->id, 'effective_from' => self::N, 'effective_to' => null, 'created_at' => now()]);

        $relationship = $this->relOf($person);

        $this->assertSame([[self::M, self::N, 'NOT_RECORDED']], $this->shape($relationship['job_title_segments']), '[.., 11-01) does not touch the month');
        $this->assertSame([[self::M, self::N, 'NOT_RECORDED']], $this->shape($relationship['organizational_placement_segments']), '[12-01, ..) is outside the month');
    }

    public function test_corrupt_hierarchy_fails_explicitly_instead_of_picking_a_row(): void
    {
        $this->assertSame(['a', 'b', 'c'], OrganizationalHierarchyPaths::path('c', ['a' => ['parent_id' => null], 'b' => ['parent_id' => 'a'], 'c' => ['parent_id' => 'b']]));
        $this->expectException(InconsistentDimensionHistoryException::class);
        OrganizationalHierarchyPaths::path('a', ['a' => ['parent_id' => 'b'], 'b' => ['parent_id' => 'a']]);
    }

    // ------------------------------------------------------------------------------------------------------------
    // 13. Canonical dataset consistency
    // ------------------------------------------------------------------------------------------------------------

    public function test_every_official_section_is_derived_from_the_canonical_records(): void
    {
        $root = $this->createUnit('جذر');
        $home = $this->createUnit('فرع', $root->id);
        $dest = $this->createUnit('وجهة');
        for ($i = 0; $i < 3; $i++) {
            [$person, $rel] = $this->emp();
            $this->recordPlacement($rel, $home, '2026-02-01');
            $title = $this->title($rel, '2026-02-01');
            if ($i === 0) {
                app(DefineJobTitleAdministratorClassificationPeriod::class)->handle($title, true, '2026-01-01', null);
                app(StartFullSecondment::class)->handle($rel->refresh(), $dest, '2026-11-10');
            }
            if ($i === 1) {
                DB::table('hr.persons')->where('id', $person->id)->update(['gender_id' => null]);
            }
        }
        $r = $this->report();

        $titles = [];
        $classifications = ['ADMINISTRATOR' => [], 'NOT_ADMINISTRATOR' => [], 'UNMAPPED' => [], 'NOT_RECORDED' => []];
        $workplaces = [];
        $dq = array_fill_keys(AdministrativeReportResult::DATA_QUALITY_CODES, []);
        foreach ($r->rows as $row) {
            foreach ($row->relationships as $rel) {
                foreach ($rel['job_title_segments'] as $s) {
                    $classifications[$s['administrator_classification']][$row->personId] = true;
                    if ($s['state'] === 'RESOLVED') {
                        $titles[$s['job_title']['id']][$row->personId] = true;
                    }
                }
                foreach ($rel['actual_workplace_segments'] as $s) {
                    if ($s['state'] === 'RESOLVED') {
                        $workplaces[$s['organizational_unit_id']][$row->personId] = true;
                    }
                }
            }
            foreach ($row->dataQuality as $code) {
                $dq[$code][$row->personId] = true;
            }
        }

        $this->assertSame($r->overallHeadcount, count($r->rows));
        $this->assertSame($r->overallHeadcount, $r->sections['general_summary']['overall_headcount']);
        $this->assertSame(array_map(fn ($c) => count($classifications[$c]), AdministrativeSections::ADMINISTRATOR_CLASSIFICATIONS), array_column($r->sections['administrator_classification'], 'person_count'));
        foreach ($r->sections['job_titles']['titles'] as $t) {
            $this->assertSame(count($titles[$t['job_title']['id']]), $t['person_count']);
        }
        foreach ($r->sections['actual_workplaces']['workplaces'] as $w) {
            $this->assertSame(count($workplaces[$w['unit_id']]), $w['person_count']);
        }
        $this->assertSame(count($workplaces), count($r->sections['actual_workplaces']['workplaces']));
        foreach ($r->sections['data_quality'] as $entry) {
            if ($entry['code'] !== 'INDETERMINATE_DUTY_STATE') {
                $this->assertSame(count($dq[$entry['code']]), $entry['person_count'], $entry['code']);
            }
        }
        $this->assertSame(array_map(fn ($row) => $row->personId, $r->rows), $r->sections['employee_drilldown']['person_ids']);
    }

    // ------------------------------------------------------------------------------------------------------------
    // 14. API and security
    // ------------------------------------------------------------------------------------------------------------

    private function viewer(array $permissions = [Perm::MONTHLY_ADMINISTRATIVE_REPORT_VIEW]): void
    {
        $principal = $this->createPrincipal();
        $this->assignRole($principal, $this->createRoleWithPermissions($permissions));
        $this->actingAs($principal, 'web');
    }

    public function test_the_endpoint_returns_metadata_sections_and_rows_from_one_computation(): void
    {
        [, $rel] = $this->emp();
        $this->recordPlacement($rel, $this->createUnit('م'), '2026-02-01');
        $this->viewer();

        $json = $this->getJson(self::URL.'?month='.self::M)->assertOk()->json();

        foreach (['month', 'month_start', 'next_month_start', 'month_end', 'metadata', 'general_summary', 'administrator_classification', 'job_titles', 'gender', 'organizational_placement', 'actual_workplaces', 'employee_drilldown', 'data_quality', 'rows'] as $key) {
            $this->assertArrayHasKey($key, $json);
        }
        $this->assertSame(count($json['rows']), $json['general_summary']['overall_headcount']);
        $this->assertFalse($json['metadata']['semantics']['multi_value_sections_reconcile_to_overall']);
        $this->assertSame('DISTINCT_PERSON', $json['metadata']['semantics']['overall_headcount']);
        $this->assertSame(self::M, $json['month_start']);
        $this->assertSame('2026-11-30', $json['month_end']);
        $this->assertSame($json['rows'] === [] ? [] : array_column($json['rows'], 'person_id'), $json['employee_drilldown']['person_ids']);
    }

    public function test_the_endpoint_requires_its_dedicated_permission_a_valid_month_and_authentication(): void
    {
        $this->getJson(self::URL.'?month='.self::M)->assertStatus(401);

        $this->viewer([Perm::HUMAN_CADRE_VIEW, Perm::MONTHLY_NOT_ON_DUTY_VIEW, Perm::PERSONS_VIEW]);
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

    public function test_the_route_is_one_read_only_get_with_a_permission_and_no_pagination_filter_or_export_parameter(): void
    {
        $matches = array_values(array_filter(iterator_to_array(Route::getRoutes()), fn ($route) => $route->uri() === 'api/v1/hr/administrative-report'));
        $this->assertCount(1, $matches);

        $this->assertSame(['GET', 'HEAD'], $matches[0]->methods());
        $this->assertSame('api.v1.hr.administrative-report.index', $matches[0]->getName());
        $this->assertContains('permission:'.Perm::MONTHLY_ADMINISTRATIVE_REPORT_VIEW, $matches[0]->gatherMiddleware());
        $this->assertDoesNotMatchRegularExpression('/MonthlyReporting|MonthlyDimension|dimension/i', $matches[0]->getActionName());
        $this->assertSame([], glob(base_path('app/Modules/*/Presentation/*/*Monthly*.php')), 'no Monthly* presentation class');

        $this->viewer();
        $plain = $this->getJson(self::URL.'?month='.self::M)->json('general_summary.overall_headcount');
        $this->assertSame($plain, $this->getJson(self::URL.'?month='.self::M.'&per_page=1&page=2&sort=name&format=xlsx&gender=MALE')->json('general_summary.overall_headcount'), 'the only public input is month');
    }

    // ------------------------------------------------------------------------------------------------------------
    // 15. Query architecture
    // ------------------------------------------------------------------------------------------------------------

    public function test_the_statement_count_is_a_pinned_constant_independent_of_population_size(): void
    {
        $measure = function (int $people): array {
            for ($i = 0; $i < $people; $i++) {
                [$person, $rel] = $this->emp($i % 2 === 0 ? 'permanent' : 'contract');
                $home = $this->createUnit('م'.$i);
                $this->recordPlacement($rel, $home, '2026-02-01');
                $this->title($rel, '2026-02-01');
                $this->title($rel, '2026-11-15');
                app(StartFullSecondment::class)->handle($rel->refresh(), $this->createUnit('و'.$i), '2026-11-10');
            }

            return $this->statements(fn () => $this->report());
        };

        $small = $measure(2);
        $large = $measure(12);

        $this->assertCount(18, $small, 'S37 (8) + S40 enrichment (7) + identity/gender + placement periods + organizational hierarchy');
        $this->assertCount(count($small), $large, 'no N+1: the count does not depend on Persons, relationships, titles, workplaces or units');
        foreach ($large as $sql) {
            $this->assertMatchesRegularExpression('/^(select|with)\\b/', strtolower(ltrim($sql)), 'read-only');
        }
        foreach (['FROM hr.persons p LEFT JOIN ref.genders g', 'FROM hr.organizational_placement_periods pp', 'WITH RECURSIVE tree AS'] as $needle) {
            $this->assertCount(1, array_filter($large, fn ($sql) => str_contains($sql, $needle)), "one batch: {$needle}");
        }
    }

    public function test_s37_runs_exactly_once_and_s40_is_reused_through_the_precomputed_population(): void
    {
        $this->emp();

        $statements = $this->statements(fn () => $this->report());

        $this->assertCount(1, array_filter($statements, fn ($sql) => str_contains($sql, 'SELECT r.id, r.person_id, r.employment_type_id')), 'the canonical S37 population is computed once');
        $this->assertCount(1, array_filter($statements, fn ($sql) => str_contains($sql, 'FROM hr.employment_job_title_periods p')), 'S40 enrichment ran once, on the precomputed population');
        $this->assertCount(8, $this->statements(fn () => app(ListMonthlyReportingPopulation::class)(self::M)), 'S37 keeps its eight statements');
        $this->assertCount(15, $this->statements(fn () => app(ListMonthlyWorkforceDimensions::class)(self::M)), 'the existing S40 path keeps its fifteen statements');
    }

    public function test_r2_contains_no_population_classification_or_forbidden_concepts_of_its_own(): void
    {
        $code = preg_replace('#/\*.*?\*/|//[^\n]*#s', '', (string) file_get_contents(base_path(self::BUILDER)));

        $this->assertSame(1, substr_count($code, '($this->population)('), 'ListMonthlyReportingPopulation is called exactly once');
        $this->assertSame(1, substr_count($code, '->fromPopulation('), 'S40 is reused through the additive entry point');
        $this->assertSame(1, substr_count($code, 'MonthlyDutyClassification::HAS_ON_DUTY'), 'only the HAS_ON_DUTY filter over S37\'s own classification');
        foreach (['MonthlyStatusSegmentation', 'MonthlyDutyClassification::classify', 'MonthlyWorkplaceSegmentation', 'ListReportingPopulationAsOf', 'ResolveEmployment', 'employment_status_periods', 'employment_job_title_periods', 'job_title_administrator_classifications', 'full_secondment_periods', 'workplace_assignment_periods', 'partial_secondment_periods', 'work_schedule'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $code, "R2 re-derives nothing: {$forbidden}");
        }
        foreach (['attendance', 'timesheet', 'fte', 'payroll', 'percent', 'xlsx', 'pdf', 'supervis'] as $excluded) {
            $this->assertStringNotContainsString($excluded, strtolower($code), "excluded concept: {$excluded}");
        }
    }

    public function test_s42_adds_no_schema_object_and_no_frontend_or_output_file(): void
    {
        $this->assertCount(91, glob(base_path('database/migrations/*.php')), 'S42 adds exactly one migration (the permission seed); S43 adds one more permission seed');
        $this->assertSame('2026_10_19_000001_seed_security_monthly_administrative_report_permission.php', collect(glob(base_path('database/migrations/*.php')))->map('basename')->sort()->filter(fn ($name) => $name < '2026_10_20')->last(), 'S42 migration is the last one before S43');
        $this->assertSame(0, (int) DB::selectOne('select count(*) as c from pg_matviews')->c);
        $this->assertSame(0, DB::table('information_schema.views')->whereIn('table_schema', ['hr', 'ref', 'org', 'automation', 'reporting'])->count());
        foreach (glob(base_path('../frontend/src/*/*.ts*')) ?: [] as $file) {
            $this->assertStringNotContainsString('administrative-report', (string) file_get_contents($file), 'no frontend consumer');
        }
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path('app'), \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            $this->assertDoesNotMatchRegularExpression('/(Xlsx|Pdf|Csv|Print|Dashboard|Export|Attendance|Timesheet|Payroll)/i', $file->getFilename(), 'no output, attendance or payroll class');
            $this->assertDoesNotMatchRegularExpression('/(SupportServicesReport|VolunteersReport|UnemploymentReport|Report[45]\b|R[45]Report)/i', $file->getFilename(), 'R4/R5 stay unimplemented');
        }
    }
}
