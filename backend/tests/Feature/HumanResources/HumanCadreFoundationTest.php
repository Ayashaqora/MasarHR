<?php

namespace Tests\Feature\HumanResources;

use App\Modules\HumanResources\Application\Commands\DesignateQualificationAsPrimary;
use App\Modules\HumanResources\Application\Commands\EndEmploymentRelationship;
use App\Modules\HumanResources\Application\Commands\RecordEmploymentSpecialtyPeriod;
use App\Modules\HumanResources\Application\Commands\RecordPersonQualification;
use App\Modules\HumanResources\Application\Queries\Reporting\BuildHumanCadreResult;
use App\Modules\HumanResources\Application\Queries\Reporting\HumanCadrePersonRecord;
use App\Modules\HumanResources\Application\Queries\Reporting\HumanCadreResult;
use App\Modules\HumanResources\Application\Queries\Reporting\ListMonthlyReportingPopulation;
use App\Modules\HumanResources\Application\Queries\Reporting\ListMonthlyWorkforceDimensions;
use App\Modules\HumanResources\Infrastructure\Authorization\HumanResourcesPermissionCatalog as Perm;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\EmploymentRelationship;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\Person;
use App\Modules\Reference\Application\Commands\DefineSpecialtyCadreCategoryMappingPeriod;
use App\Modules\Reference\Infrastructure\Persistence\Eloquent\MonthlyCadreCategory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/**
 * S41 REPORT-1 Monthly Human Cadre (docs/human-cadre-report-foundation-specification.md): ONE canonical record per Person from the
 * canonical S37 population enriched by S40, with the official summaries derived from those same records. Real PostgreSQL, synthetic
 * data only. The report month is November 2026: [2026-11-01, 2026-12-01), the age reference / month end 2026-11-30. Assertions
 * about fixtures target the Persons this test creates; the reconciliation invariants hold for the WHOLE result.
 */
class HumanCadreFoundationTest extends HumanResourcesTestCase
{
    private const M = '2026-11-01';

    private const N = '2026-12-01';

    private const URL = '/api/v1/hr/human-cadre';

    /** S42: the exact paths of the five authorized R2 files (the R2 name is forbidden for every other file). */
    private const S42_AUTHORIZED_R2_FILES = [
        'app/Modules/HumanResources/Application/Queries/Reporting/AdministrativeReportPersonRecord.php',
        'app/Modules/HumanResources/Application/Queries/Reporting/AdministrativeReportResult.php',
        'app/Modules/HumanResources/Application/Queries/Reporting/BuildAdministrativeReportResult.php',
        'app/Modules/HumanResources/Presentation/Http/Controllers/AdministrativeReportController.php',
        'app/Modules/HumanResources/Presentation/Http/Resources/AdministrativeReportResource.php',
    ];

    private const BUILDER = 'app/Modules/HumanResources/Application/Queries/Reporting/BuildHumanCadreResult.php';

    // ------------------------------------------------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------------------------------------------------

    private function report(string $month = self::M): HumanCadreResult
    {
        return app(BuildHumanCadreResult::class)($month);
    }

    /** @return array{0: Person, 1: EmploymentRelationship} */
    private function emp(string $type = 'permanent', string $from = '2026-01-01', ?Person $person = null): array
    {
        $person ??= $this->createPersonRecord();

        return [$person, $this->createEmploymentRelationship($person, $type, null, $from)];
    }

    private function record(Person $person, string $month = self::M): HumanCadrePersonRecord
    {
        $rows = array_values(array_filter($this->report($month)->rows, fn (HumanCadrePersonRecord $row) => $row->personId === $person->id));
        $this->assertCount(1, $rows, 'exactly one canonical record per Person');

        return $rows[0];
    }

    private function recordOrNull(Person $person, string $month = self::M): ?HumanCadrePersonRecord
    {
        foreach ($this->report($month)->rows as $row) {
            if ($row->personId === $person->id) {
                return $row;
            }
        }

        return null;
    }

    private function end(Person $person, EmploymentRelationship $rel, string $to): void
    {
        $rel->refresh();
        app(EndEmploymentRelationship::class)->handle($person, $rel, $rel->version, $to, false);
    }

    private function rawStatus(EmploymentRelationship $rel, string $code, string $from, ?string $to = null, ?string $pay = null): void
    {
        DB::table('hr.employment_status_periods')->insert([
            'id' => (string) Str::uuid7(), 'employment_relationship_id' => $rel->id, 'status_detail_id' => $this->statusDetail($code)->id,
            'effective_from' => $from, 'effective_to' => $to, 'travel_pay_status' => $pay, 'created_at' => now(),
        ]);
    }

    private function legacyRelationship(Person $person, string $from): string
    {
        $id = (string) Str::uuid7();
        DB::table('hr.employment_relationships')->insert([
            'id' => $id, 'person_id' => $person->id, 'employment_type_id' => $this->employmentType('contract')->id,
            'employee_number' => 'CN-LEGACY-'.Str::upper(Str::random(8)), 'employee_number_scheme' => 'CONTRACT', 'effective_from' => $from,
            'effective_to' => null, 'end_knowledge_state' => 'UNKNOWN_LEGACY', 'version' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    private function specialty(EmploymentRelationship $rel, string $from, $specialty = null)
    {
        $specialty ??= $this->createSyntheticSpecialty();
        app(RecordEmploymentSpecialtyPeriod::class)->handle($rel, $specialty, $from);

        return $specialty;
    }

    private function mapSpecialty($specialty, string $cadre, string $from, ?string $to = null): void
    {
        app(DefineSpecialtyCadreCategoryMappingPeriod::class)->handle($specialty, MonthlyCadreCategory::query()->where('code', $cadre)->firstOrFail(), $from, $to);
    }

    private function qualify(Person $person, bool $degree = true)
    {
        return app(RecordPersonQualification::class)->handle($person, $degree ? $this->createSyntheticAcademicDegree() : null, $degree ? null : $this->createSyntheticQualificationType());
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
    // A. Population and the selected relationship
    // ------------------------------------------------------------------------------------------------------------

    public function test_a_full_month_person_has_one_record_classified_at_month_end(): void
    {
        [$person, $rel] = $this->emp();

        $record = $this->record($person);

        $this->assertSame($rel->id, $record->selectedRelationship['employment_relationship_id']);
        $this->assertSame('2026-11-30', $record->classificationDate);
        $this->assertSame([$person->national_id, 'موظف اختبار'], [$record->nationalId, $record->fullNameAr], 'drilldown identity');
        $this->assertSame('permanent', $record->selectedRelationship['employment_type']['code']);
    }

    public function test_a_relationship_starting_mid_month_qualifies_and_is_classified_at_month_end(): void
    {
        [$person, $rel] = $this->emp('permanent', '2026-11-10');

        $record = $this->record($person);

        $this->assertSame([$rel->id, '2026-11-30'], [$record->selectedRelationship['employment_relationship_id'], $record->classificationDate]);
        $this->assertSame(21, $record->service['service_days'], '[11-10, 12-01)');
    }

    public function test_a_relationship_ending_mid_month_qualifies_and_is_classified_at_the_last_active_date(): void
    {
        [$person, $rel] = $this->emp();
        $this->end($person, $rel, '2026-11-20');

        $record = $this->record($person);

        $this->assertSame('2026-11-19', $record->classificationDate, 'the last active DATE before the exclusive effective_to');
        $this->assertSame(323, $record->service['service_days'], '[2026-01-01, 2026-11-20) = 304 + 19');
    }

    public function test_the_boundary_relationships_are_excluded_by_the_half_open_month(): void
    {
        [$endedAtStart, $a] = $this->emp();
        $this->end($endedAtStart, $a, self::M);
        [$startsAtNext] = $this->emp('permanent', self::N);

        $this->assertNull($this->recordOrNull($endedAtStart), 'ended exactly on month_start: not in the month');
        $this->assertNull($this->recordOrNull($startsAtNext), 'starts exactly on next_month_start: not in the month');
    }

    public function test_a_same_month_reappointment_is_one_person_with_the_latest_relationship_selected_and_the_service_summed(): void
    {
        [$person, $old] = $this->emp('contract', '2026-01-01');
        $this->end($person, $old, '2026-11-10');
        $new = $this->createEmploymentRelationship($person, 'contract', null, '2026-11-10');

        $record = $this->record($person);

        $this->assertSame($new->id, $record->selectedRelationship['employment_relationship_id'], 'the latest qualifying relationship');
        $this->assertSame('2026-11-30', $record->classificationDate);
        $this->assertSame(313 + 21, $record->service['service_days'], 'earlier service [01-01, 11-10) + [11-10, 12-01)');
        $ids = array_map(fn ($row) => $row->personId, $this->report()->rows);
        $this->assertSame($ids, array_values(array_unique($ids)), 'one Person never counted twice');
    }

    // ------------------------------------------------------------------------------------------------------------
    // B. Specialty and cadre classification
    // ------------------------------------------------------------------------------------------------------------

    public function test_a_specialty_changing_mid_month_contributes_the_one_specialty_effective_at_the_classification_date(): void
    {
        [$person, $rel] = $this->emp();
        $first = $this->specialty($rel, '2026-01-01');
        $second = $this->specialty($rel, '2026-11-10');
        $this->mapSpecialty($second, 'nursing', '2026-01-01');

        $record = $this->record($person);

        $this->assertSame(['RESOLVED', $second->id], [$record->specialty['state'], $record->specialty['id']], 'month-end classification: the later specialty');
        $this->assertNotSame($first->id, $record->specialty['id']);
        $this->assertSame(['RESOLVED', 'nursing'], [$record->cadre['state'], $record->cadre['code']]);
    }

    public function test_a_relationship_ending_mid_month_classifies_at_its_last_active_date_not_at_month_end(): void
    {
        [$person, $rel] = $this->emp();
        $specialty = $this->specialty($rel, '2026-01-01');
        $this->mapSpecialty($specialty, 'doctors', '2026-01-01', '2026-11-15');
        $this->mapSpecialty($specialty, 'nursing', '2026-11-15');
        [$endedEarly, $earlyRel] = $this->emp();
        $this->specialty($earlyRel, '2026-01-01', $specialty);
        $this->end($endedEarly, $earlyRel, '2026-11-10');

        $this->assertSame('nursing', $this->record($person)->cadre['code'], 'month-end: the mapping effective on 11-30');
        $early = $this->record($endedEarly);
        $this->assertSame(['2026-11-09', 'doctors'], [$early->classificationDate, $early->cadre['code']], 'the mapping effective on the last active date, never today\'s for the whole month');
    }

    public function test_a_person_without_a_specialty_is_unclassified_not_recorded(): void
    {
        [$person] = $this->emp();

        $record = $this->record($person);

        $this->assertSame('NOT_RECORDED', $record->specialty['state']);
        $this->assertSame(['UNCLASSIFIED', 'NOT_RECORDED', null], [$record->cadre['state'], $record->cadre['unclassified_reason'], $record->cadre['code']]);
    }

    public function test_an_unmapped_specialty_counts_under_its_real_specialty_and_is_unclassified_unmapped_never_other(): void
    {
        [$person, $rel] = $this->emp();
        $specialty = $this->specialty($rel, '2026-01-01');

        $record = $this->record($person);

        $this->assertSame(['RESOLVED', $specialty->id], [$record->specialty['state'], $record->specialty['id']]);
        $this->assertSame(['UNCLASSIFIED', 'UNMAPPED', null], [$record->cadre['state'], $record->cadre['unclassified_reason'], $record->cadre['code']]);
        $this->assertStringNotContainsString('other', json_encode($record->cadre));
    }

    public function test_a_mapping_that_starts_after_the_classification_date_does_not_classify(): void
    {
        [$person, $rel] = $this->emp();
        $specialty = $this->specialty($rel, '2026-01-01');
        $this->mapSpecialty($specialty, 'pharmacy', self::N);

        $this->assertSame('UNMAPPED', $this->record($person)->cadre['unclassified_reason']);
    }

    // ------------------------------------------------------------------------------------------------------------
    // C. Employment type and gender
    // ------------------------------------------------------------------------------------------------------------

    public function test_the_relationship_type_is_the_structural_employment_type_of_the_selected_relationship(): void
    {
        [$permanent] = $this->emp('permanent');
        [$contract] = $this->emp('contract');

        $this->assertSame('permanent', $this->record($permanent)->selectedRelationship['employment_type']['code']);
        $this->assertSame('contract', $this->record($contract)->selectedRelationship['employment_type']['code']);
        $this->assertNotNull($this->record($contract)->selectedRelationship['employment_type']['name_ar']);
    }

    public function test_gender_is_current_recorded_and_a_missing_gender_is_not_recorded(): void
    {
        [$male] = $this->emp();
        [$none] = $this->emp();
        DB::table('hr.persons')->where('id', $none->id)->update(['gender_id' => null]);

        $this->assertSame(['RECORDED', 'male'], [$this->record($male)->gender['state'], $this->record($male)->gender['code']]);
        $this->assertSame(['NOT_RECORDED', null], [$this->record($none)->gender['state'], $this->record($none)->gender['code']]);
    }

    // ------------------------------------------------------------------------------------------------------------
    // D. Primary qualification dimension
    // ------------------------------------------------------------------------------------------------------------

    public function test_a_person_without_a_qualification_is_not_recorded_without_a_data_quality_flag(): void
    {
        [$person] = $this->emp();

        $record = $this->record($person);

        $this->assertSame('NOT_RECORDED', $record->qualification['state']);
        $this->assertNotContains(HumanCadreResult::DQ_PRIMARY_QUALIFICATION_REQUIRED, $record->dataQuality);
    }

    public function test_the_primary_qualification_is_the_only_one_used_and_a_later_one_never_replaces_it(): void
    {
        [$person] = $this->emp();
        $first = $this->qualify($person);
        $this->qualify($person, false);

        $record = $this->record($person);

        $this->assertSame(['PRIMARY', $first->id], [$record->qualification['state'], $record->qualification['qualification_id']]);
        $this->assertNotNull($record->qualification['academic_degree']['code']);
        $this->assertNotContains(HumanCadreResult::DQ_PRIMARY_QUALIFICATION_REQUIRED, $record->dataQuality);
    }

    public function test_several_qualifications_with_no_primary_are_not_recorded_with_primary_qualification_required(): void
    {
        [$person] = $this->emp();
        $degree = $this->createSyntheticAcademicDegree();
        $type = $this->createSyntheticQualificationType();
        foreach ([[$degree->id, null], [null, $type->id]] as [$d, $t]) {
            DB::table('hr.person_qualifications')->insert(['id' => (string) Str::uuid7(), 'person_id' => $person->id, 'academic_degree_id' => $d, 'qualification_type_id' => $t, 'is_primary' => false, 'created_at' => now()]);
        }

        $record = $this->record($person);

        $this->assertSame('NOT_RECORDED', $record->qualification['state'], 'no Primary is ever inferred from created_at, degree or order');
        $this->assertContains(HumanCadreResult::DQ_PRIMARY_QUALIFICATION_REQUIRED, $record->dataQuality);
    }

    public function test_qualification_is_current_recorded_so_a_rerun_of_an_old_month_uses_the_current_primary(): void
    {
        [$person] = $this->emp('permanent', '2025-01-01');
        $first = $this->qualify($person);
        $second = $this->qualify($person, false);
        $this->assertSame($first->id, $this->record($person, '2026-03-01')->qualification['qualification_id']);

        app(DesignateQualificationAsPrimary::class)->handle($person, $second);

        $this->assertSame($second->id, $this->record($person, '2026-03-01')->qualification['qualification_id'], 'the historical month now shows the current Primary');
    }

    // ------------------------------------------------------------------------------------------------------------
    // E. Age
    // ------------------------------------------------------------------------------------------------------------

    public function test_age_is_completed_calendar_years_at_month_end_with_missing_and_future_birth_dates_handled(): void
    {
        [$ok] = $this->emp();
        [$missing] = $this->emp();
        [$future] = $this->emp();
        DB::table('hr.persons')->where('id', $ok->id)->update(['birth_date' => '1990-11-30']);
        DB::table('hr.persons')->where('id', $missing->id)->update(['birth_date' => null]);
        DB::table('hr.persons')->where('id', $future->id)->update(['birth_date' => '2026-12-01']);

        $this->assertSame(['CALCULABLE', 36, '35-44'], [$this->record($ok)->age['state'], $this->record($ok)->age['years'], $this->record($ok)->age['band']]);
        $this->assertSame(['NOT_RECORDED', null, 'NOT_RECORDED'], [$this->record($missing)->age['state'], $this->record($missing)->age['years'], $this->record($missing)->age['band']]);
        $after = $this->record($future);
        $this->assertSame(['NOT_CALCULABLE', null, 'NOT_RECORDED'], [$after->age['state'], $after->age['years'], $after->age['band']], 'never a negative age');
        $this->assertContains(HumanCadreResult::DQ_BIRTH_DATE_AFTER_REPORT_DATE, $after->dataQuality);
        $this->assertNotContains(HumanCadreResult::DQ_BIRTH_DATE_AFTER_REPORT_DATE, $this->record($missing)->dataQuality);
    }

    // ------------------------------------------------------------------------------------------------------------
    // F. Service
    // ------------------------------------------------------------------------------------------------------------

    public function test_the_service_of_an_untouched_relationship_is_its_documented_days_with_a_years_plus_days_display(): void
    {
        [$person] = $this->emp();
        [$long] = $this->emp('permanent', '2000-01-01');

        $short = $this->record($person)->service;
        $this->assertSame(['CALCULABLE', 334, 0, 334, '0 years + 334 days', '<5', null], [$short['state'], $short['service_days'], $short['completed_service_years'], $short['remaining_service_days'], $short['display'], $short['band'], $short['reason']]);

        $days = $this->record($long)->service;
        $this->assertSame(intdiv($days['service_days'], 365), $days['completed_service_years']);
        $this->assertSame($days['completed_service_years'].' years + '.$days['remaining_service_days'].' days', $days['display']);
        $this->assertSame('25-29', $days['band'], '2000-01-01 .. 2026-11-30 = 9830 days = 26 years');
    }

    public function test_status_rules_apply_to_the_service_of_the_selected_and_earlier_relationships(): void
    {
        [$unpaid, $a] = $this->emp();
        $this->rawStatus($a, 'unpaid_leave', '2026-03-01', '2026-04-01');
        [$paidTravel, $b] = $this->emp();
        $this->rawStatus($b, 'traveling', '2026-03-01', '2026-04-01', 'PAID');
        [$unpaidTravel, $c] = $this->emp();
        $this->rawStatus($c, 'traveling', '2026-03-01', '2026-04-01', 'UNPAID');
        [$nullTravel, $d] = $this->emp();
        $this->rawStatus($d, 'traveling', '2026-03-01', '2026-04-01');
        [$captive, $e] = $this->emp();
        $this->rawStatus($e, 'captive', '2026-03-01');

        $this->assertSame(303, $this->record($unpaid)->service['service_days']);
        $this->assertSame([334, []], [$this->record($paidTravel)->service['service_days'], $this->record($paidTravel)->dataQuality]);
        $this->assertSame([303, []], [$this->record($unpaidTravel)->service['service_days'], $this->record($unpaidTravel)->dataQuality]);
        $this->assertSame([334, [HumanCadreResult::DQ_TRAVEL_PAY_STATUS_NOT_RECORDED]], [$this->record($nullTravel)->service['service_days'], $this->record($nullTravel)->dataQuality]);
        $this->assertSame(334, $this->record($captive)->service['service_days']);
    }

    public function test_external_sick_leave_is_90_days_per_continuous_run_in_the_report(): void
    {
        [$exactly, $a] = $this->emp();
        $this->rawStatus($a, 'external_sick_leave', '2026-02-01', '2026-05-02');          // exactly 90 days
        [$over, $b] = $this->emp();
        $this->rawStatus($b, 'external_sick_leave', '2026-02-01', '2026-05-03');          // 91 days
        [$gap, $c] = $this->emp();
        $this->rawStatus($c, 'external_sick_leave', '2026-02-01', '2026-03-23');          // 50 + date gap + 50
        $this->rawStatus($c, 'external_sick_leave', '2026-03-24', '2026-05-13');

        $this->assertSame(334, $this->record($exactly)->service['service_days']);
        $this->assertSame(333, $this->record($over)->service['service_days']);
        $this->assertSame(334, $this->record($gap)->service['service_days'], 'a date gap resets the allowance');
    }

    public function test_an_unknown_legacy_relationship_end_keeps_the_person_with_incomplete_service_and_every_other_dimension(): void
    {
        $person = $this->createPersonRecord();
        $legacyId = $this->legacyRelationship($person, '2026-01-01');
        $specialty = $this->createSyntheticSpecialty();
        DB::table('hr.employment_specialty_periods')->insert(['id' => (string) Str::uuid7(), 'employment_relationship_id' => $legacyId, 'specialty_id' => $specialty->id, 'effective_from' => '2026-01-01', 'effective_to' => null, 'created_at' => now()]);
        $this->mapSpecialty($specialty, 'laboratory', '2026-01-01');

        $record = $this->record($person);

        $this->assertSame(['INCOMPLETE', null, 'INCOMPLETE', 'UNKNOWN_LEGACY_RELATIONSHIP_END'], [$record->service['state'], $record->service['service_days'], $record->service['band'], $record->service['reason']]);
        $this->assertContains(HumanCadreResult::DQ_UNKNOWN_LEGACY_RELATIONSHIP_END, $record->dataQuality);
        $this->assertSame(['RESOLVED', 'laboratory', 'contract'], [$record->cadre['state'], $record->cadre['code'], $record->selectedRelationship['employment_type']['code']], 'all other dimensions stay available');
    }

    // ------------------------------------------------------------------------------------------------------------
    // G. Summaries reconcile with the canonical records and with their drilldowns
    // ------------------------------------------------------------------------------------------------------------

    private function populateVariedPersons(): void
    {
        $mapped = $this->createSyntheticSpecialty();
        $this->mapSpecialty($mapped, 'doctors', '2026-01-01');
        for ($i = 0; $i < 4; $i++) {
            [$person, $rel] = $this->emp($i % 2 === 0 ? 'permanent' : 'contract', $i === 3 ? '2026-11-10' : '2020-01-01');
            if ($i === 0) {
                $this->specialty($rel, '2020-01-01', $mapped);
                $this->qualify($person);
            }
            if ($i === 1) {
                $this->specialty($rel, '2020-01-01'); // unmapped
            }
            if ($i === 2) {
                DB::table('hr.persons')->where('id', $person->id)->update(['birth_date' => null, 'gender_id' => null]);
            }
        }
        $legacy = $this->createPersonRecord();
        $this->legacyRelationship($legacy, '2026-01-01');
    }

    public function test_every_summary_reconciles_to_the_overall_headcount(): void
    {
        $this->populateVariedPersons();

        $r = $this->report();
        $s = $r->summaries;

        $this->assertSame(count($r->rows), $r->overallHeadcount);
        $this->assertSame($r->overallHeadcount, array_sum(array_column($s['cadre']['categories'], 'count')) + $s['cadre']['unclassified']['count'], 'overall = cadre buckets + UNCLASSIFIED');
        $this->assertSame($s['cadre']['unclassified']['count'], array_sum($s['cadre']['unclassified']['reasons']));
        $this->assertSame($r->overallHeadcount, array_sum(array_column($s['specialty']['specialties'], 'count')) + $s['specialty']['not_recorded'], 'overall = specialties + NOT_RECORDED');
        $this->assertSame($r->overallHeadcount, array_sum(array_column($s['employment_type'], 'count')));
        $this->assertSame($r->overallHeadcount, array_sum(array_column($s['gender']['genders'], 'count')) + $s['gender']['not_recorded']);
        $this->assertSame($r->overallHeadcount, array_sum(array_column($s['qualification']['primary_qualifications'], 'count')) + $s['qualification']['not_recorded']);
        $this->assertSame($r->overallHeadcount, array_sum(array_column($s['age'], 'count')), 'overall = the six age bands + NOT_RECORDED');
        $this->assertSame(['<25', '25-34', '35-44', '45-54', '55-64', '65+', 'NOT_RECORDED'], array_column($s['age'], 'band'));
        $this->assertSame($r->overallHeadcount, array_sum(array_column($s['service'], 'count')), 'overall = the seven service bands + INCOMPLETE');
        $this->assertSame(['<5', '5-9', '10-14', '15-19', '20-24', '25-29', '30+', 'INCOMPLETE'], array_column($s['service'], 'band'));
        $this->assertGreaterThanOrEqual(12, count($s['cadre']['categories']), 'every official cadre category is listed, zero-count ones included');
    }

    public function test_every_summary_bucket_equals_the_count_of_the_canonical_rows_that_match_it(): void
    {
        $this->populateVariedPersons();
        $r = $this->report();
        $s = $r->summaries;
        $rows = $r->rows;
        $count = fn (callable $filter) => count(array_filter($rows, $filter));

        foreach ($s['cadre']['categories'] as $bucket) {
            $this->assertSame($bucket['count'], $count(fn ($row) => $row->cadre['state'] === 'RESOLVED' && $row->cadre['code'] === $bucket['code']), "cadre {$bucket['code']}");
        }
        $this->assertSame($s['cadre']['unclassified']['count'], $count(fn ($row) => $row->cadre['state'] === 'UNCLASSIFIED'));
        foreach (['NOT_RECORDED', 'UNMAPPED'] as $reason) {
            $this->assertSame($s['cadre']['unclassified']['reasons'][$reason], $count(fn ($row) => $row->cadre['unclassified_reason'] === $reason));
        }
        foreach ($s['specialty']['specialties'] as $bucket) {
            $this->assertSame($bucket['count'], $count(fn ($row) => $row->specialty['id'] === $bucket['specialty_id']));
        }
        $this->assertSame($s['specialty']['not_recorded'], $count(fn ($row) => $row->specialty['state'] === 'NOT_RECORDED'));
        foreach ($s['employment_type'] as $bucket) {
            $this->assertSame($bucket['count'], $count(fn ($row) => $row->selectedRelationship['employment_type']['code'] === $bucket['code']));
        }
        $this->assertSame($s['gender']['not_recorded'], $count(fn ($row) => $row->gender['state'] === 'NOT_RECORDED'));
        $this->assertSame($s['qualification']['not_recorded'], $count(fn ($row) => $row->qualification['state'] === 'NOT_RECORDED'));
        foreach ($s['age'] as $bucket) {
            $this->assertSame($bucket['count'], $count(fn ($row) => $row->age['band'] === $bucket['band']));
        }
        foreach ($s['service'] as $bucket) {
            $this->assertSame($bucket['count'], $count(fn ($row) => $row->service['band'] === $bucket['band']));
        }
    }

    public function test_the_canonical_rows_are_unique_persons_in_a_stable_order(): void
    {
        $this->populateVariedPersons();

        $ids = array_map(fn ($row) => $row->personId, $this->report()->rows);

        $this->assertSame($ids, array_values(array_unique($ids)));
        $sorted = $ids;
        sort($sorted);
        $this->assertSame($sorted, $ids, 'ordered by Person id (the S37 order)');
        $canonical = array_map(fn ($row) => $row->personId, app(ListMonthlyReportingPopulation::class)(self::M)->persons);
        $this->assertSame($canonical, $ids, 'exactly the canonical S37 Person set');
    }

    public function test_an_empty_month_reports_zero_with_the_full_band_and_category_structure(): void
    {
        $r = $this->report('1990-01-01');

        $this->assertSame([0, []], [$r->overallHeadcount, $r->rows]);
        $this->assertCount(7, $r->summaries['age']);
        $this->assertCount(8, $r->summaries['service']);
        $this->assertGreaterThanOrEqual(12, count($r->summaries['cadre']['categories']));
    }

    // ------------------------------------------------------------------------------------------------------------
    // H. Query budget, canonical reuse, no second engine
    // ------------------------------------------------------------------------------------------------------------

    public function test_the_statement_count_is_a_pinned_constant_independent_of_population_size(): void
    {
        $measure = function (int $people): array {
            for ($i = 0; $i < $people; $i++) {
                [$person, $rel] = $this->emp($i % 2 === 0 ? 'permanent' : 'contract');
                $specialty = $this->specialty($rel, '2026-01-01');
                $this->mapSpecialty($specialty, 'doctors', '2026-01-01', '2026-11-15');
                $this->rawStatus($rel, 'traveling', '2026-03-01', '2026-04-01');
                $this->qualify($person);
                $this->qualify($person, false);
            }

            return $this->statements(fn () => $this->report());
        };

        $small = $measure(2);
        $large = $measure(14);

        $this->assertCount(20, $small, 'S37 (8) + S40 enrichment (7) + identity + relationship history + status history + primary qualification + cadre catalog');
        $this->assertCount(count($small), $large, 'no N+1: the statement count does not depend on Persons, relationships, segments or statuses');
        foreach ($large as $sql) {
            $this->assertStringStartsWith('select', strtolower(ltrim($sql)), 'read-only');
        }
        foreach (['FROM hr.persons p LEFT JOIN ref.genders', 'FROM hr.employment_relationships r JOIN ref.employment_types', 'FROM hr.employment_status_periods sp JOIN', 'WHERE pq.is_primary AND pq.person_id', 'FROM ref.monthly_cadre_categories'] as $needle) {
            $this->assertCount(1, array_filter($large, fn ($sql) => str_contains($sql, $needle)), "one batch: {$needle}");
        }
    }

    public function test_s37_runs_exactly_once_and_the_existing_s37_and_s40_paths_keep_their_statement_counts(): void
    {
        [, $rel] = $this->emp();
        $this->specialty($rel, '2026-01-01');

        $r1 = $this->statements(fn () => $this->report());
        $this->assertCount(1, array_filter($r1, fn ($sql) => str_contains($sql, 'SELECT r.id, r.person_id, r.employment_type_id')), 'the canonical S37 population is computed once for R1');
        $this->assertCount(1, array_filter($r1, fn ($sql) => str_contains($sql, 'SELECT sp.id, sp.employment_relationship_id, sp.status_detail_id')), 'S37 reads the explicit statuses once; R1 adds only its own service-history batch');

        $this->assertCount(8, $this->statements(fn () => app(ListMonthlyReportingPopulation::class)(self::M)), 'S37 keeps its eight statements');
        $this->assertCount(15, $this->statements(fn () => app(ListMonthlyWorkforceDimensions::class)(self::M)), 'the existing S40 path keeps its fifteen statements');
        $canonical = app(ListMonthlyReportingPopulation::class)(self::M);
        $this->assertCount(7, $this->statements(fn () => app(ListMonthlyWorkforceDimensions::class)->fromPopulation($canonical)), 'the additive entry point runs only the S40 enrichment');
    }

    public function test_the_additive_s40_entry_point_returns_the_same_result_as_the_existing_path(): void
    {
        [, $rel] = $this->emp();
        $this->specialty($rel, '2026-01-01');
        $dimensions = app(ListMonthlyWorkforceDimensions::class);

        $this->assertEquals($dimensions(self::M), $dimensions->fromPopulation(app(ListMonthlyReportingPopulation::class)(self::M)));
    }

    public function test_r1_contains_no_population_or_dimension_algorithm_of_its_own(): void
    {
        $code = preg_replace('#/\*.*?\*/|//[^\n]*#s', '', (string) file_get_contents(base_path(self::BUILDER)));

        $this->assertSame(1, substr_count($code, '($this->population)('), 'ListMonthlyReportingPopulation is called exactly once');
        $this->assertSame(1, substr_count($code, '->fromPopulation('), 'S40 is reused through the additive entry point');
        foreach (['organizational_placement', 'full_secondment', 'workplace_assignment', 'partial_secondment', 'work_schedule', 'MonthlyStatusSegmentation', 'MonthlyDutyClassification', 'MonthlyWorkplaceSegmentation', 'MonthlyDimensionSegmentation', 'ListReportingPopulationAsOf', 'ResolveEmployment', 'employment_specialty_periods', 'employment_category_periods', 'employment_contract_periods', 'employment_job_title_periods', 'specialty_cadre_category_mappings'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $code, "R1 re-derives nothing: {$forbidden}");
        }
        $this->assertStringNotContainsString('contractual_effective_to', $code, 'the agreed contract term never reaches the service');
    }

    // ------------------------------------------------------------------------------------------------------------
    // I. API, security and guards
    // ------------------------------------------------------------------------------------------------------------

    private function viewer(array $permissions = [Perm::HUMAN_CADRE_VIEW]): void
    {
        $principal = $this->createPrincipal();
        $this->assignRole($principal, $this->createRoleWithPermissions($permissions));
        $this->actingAs($principal, 'web');
    }

    public function test_the_endpoint_returns_metadata_overall_summaries_and_rows_from_one_computation(): void
    {
        $this->populateVariedPersons();
        $this->viewer();

        $response = $this->getJson(self::URL.'?month='.self::M)->assertOk();

        foreach (['month', 'month_start', 'next_month_start', 'month_end', 'metadata', 'overall_headcount', 'cadre_summary', 'specialty_summary', 'employment_type_summary', 'gender_summary', 'qualification_summary', 'age_summary', 'service_summary', 'rows'] as $key) {
            $response->assertJsonStructure([$key]);
        }
        $json = $response->json();
        $this->assertSame(count($json['rows']), $json['overall_headcount']);
        $this->assertSame(['CURRENT_RECORDED_LABEL', true], [$json['metadata']['label_semantics'], $json['metadata']['semantics']['rows_are_unique_persons']]);
        $row = $json['rows'][0];
        foreach (['person_id', 'national_id', 'full_name_ar', 'gender', 'selected_relationship', 'classification_date', 'specialty', 'cadre', 'qualification', 'age', 'service', 'data_quality'] as $key) {
            $this->assertArrayHasKey($key, $row);
        }
        $this->assertSame(self::M, $json['month_start']);
        $this->assertSame('2026-11-30', $json['month_end']);
    }

    public function test_the_endpoint_requires_its_dedicated_permission_and_a_valid_explicit_month(): void
    {
        $this->viewer([Perm::MONTHLY_NOT_ON_DUTY_VIEW, Perm::PERSONS_VIEW]);
        $this->getJson(self::URL.'?month='.self::M)->assertStatus(403);

        $this->viewer();
        $statements = $this->statements(function (): void {
            foreach (['', 'month=2026-11', 'month=2026-11-15', 'month=garbage', 'month=2026-13-01'] as $query) {
                $this->getJson(self::URL.($query === '' ? '' : '?'.$query))->assertStatus(422)->assertJsonValidationErrors(['month']);
            }
        });
        $this->assertCount(0, array_filter($statements, fn ($sql) => str_contains($sql, 'hr.employment_relationships')), 'an invalid month is rejected before S37 runs');
    }

    public function test_an_unauthenticated_request_is_rejected(): void
    {
        $this->getJson(self::URL.'?month='.self::M)->assertStatus(401);
    }

    public function test_the_route_and_action_pass_every_route_guard_and_there_is_no_write_export_or_pagination(): void
    {
        $matches = array_filter(iterator_to_array(Route::getRoutes()), fn ($route) => $route->uri() === 'api/v1/hr/human-cadre');
        $this->assertCount(1, $matches);
        $route = reset($matches);

        $this->assertSame(['GET', 'HEAD'], $route->methods());
        $this->assertSame('api.v1.hr.human-cadre.index', $route->getName());
        $this->assertDoesNotMatchRegularExpression('/report|export|dashboard|as-of|pdf|xlsx|csv/i', $route->uri().$route->getActionName(), 'S27 guard');
        $this->assertDoesNotMatchRegularExpression('/monthly(?!-cadre-categories|CadreCategory)|report|export|dashboard|pdf|xlsx|csv|print/i', $route->uri(), 'S37 guard');
        $this->assertDoesNotMatchRegularExpression('/MonthlyReporting|MonthlyDimension|dimension/i', $route->getActionName());
        $this->assertContains('permission:'.Perm::HUMAN_CADRE_VIEW, $route->gatherMiddleware());
        $this->assertSame([], glob(base_path('app/Modules/*/Presentation/*/*Monthly*.php')), 'no Monthly* presentation class');

        $this->viewer();
        $this->getJson(self::URL.'?month='.self::M.'&per_page=1&page=2&cadre=doctors')->assertOk();
        $this->assertSame($this->report()->overallHeadcount, $this->getJson(self::URL.'?month='.self::M.'&per_page=1&cadre=doctors')->json('overall_headcount'), 'no pagination or filter: the one canonical computation');
    }

    public function test_s41_adds_no_frontend_export_or_output_file(): void
    {
        foreach (glob(base_path('../frontend/src/*/*.ts*')) ?: [] as $file) {
            $this->assertStringNotContainsString('human-cadre', (string) file_get_contents($file), 'no frontend consumer');
        }
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path('app'), \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            $this->assertDoesNotMatchRegularExpression('/(Xlsx|Pdf|Csv|Print|Dashboard|Export)/i', $file->getFilename(), 'no output class');
            // S42: R2 (AdministrativeReport) is now authorized — only these EXACT paths are exempt from the R2 name; R4/R5 and every other file are not.
            $relative = ltrim(str_replace('\\', '/', substr($file->getPathname(), strlen(base_path()))), '/');
            if (in_array($relative, self::S42_AUTHORIZED_R2_FILES, true)) {
                continue;
            }
            $this->assertDoesNotMatchRegularExpression('/(AdministrativeReport|SupportServicesReport|VolunteersReport|UnemploymentReport|Report[245]\b|R[245]Report)/i', $file->getFilename(), 'R2/R4/R5 stay unimplemented');
        }
    }
}
