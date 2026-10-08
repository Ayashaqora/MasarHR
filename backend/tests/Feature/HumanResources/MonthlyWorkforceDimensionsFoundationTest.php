<?php

namespace Tests\Feature\HumanResources;

use App\Modules\HumanResources\Application\Commands\EndEmploymentRelationship;
use App\Modules\HumanResources\Application\Commands\RecordEmploymentCategoryPeriod;
use App\Modules\HumanResources\Application\Commands\RecordEmploymentContractPeriod;
use App\Modules\HumanResources\Application\Commands\RecordEmploymentJobTitlePeriod;
use App\Modules\HumanResources\Application\Commands\RecordEmploymentSpecialtyPeriod;
use App\Modules\HumanResources\Application\Queries\Reporting\ListMonthlyReportingPopulation;
use App\Modules\HumanResources\Application\Queries\Reporting\ListMonthlyWorkforceDimensions;
use App\Modules\HumanResources\Application\Queries\Reporting\MonthlyDimensionPersonRow;
use App\Modules\HumanResources\Application\Queries\Reporting\MonthlyDimensionRelationship;
use App\Modules\HumanResources\Application\Queries\Reporting\MonthlyWorkforceDimensions;
use App\Modules\HumanResources\Domain\Exceptions\InconsistentDimensionHistoryException;
use App\Modules\HumanResources\Domain\MonthlyDimensionSegmentation;
use App\Modules\HumanResources\Infrastructure\Authorization\HumanResourcesPermissionCatalog;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\EmploymentRelationship;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\Person;
use App\Modules\Reference\Application\Commands\DefineContractTypePopulationMappingPeriod;
use App\Modules\Reference\Application\Commands\DefineJobTitleAdministratorClassificationPeriod;
use App\Modules\Reference\Application\Commands\DefineSpecialtyCadreCategoryMappingPeriod;
use App\Modules\Reference\Infrastructure\Persistence\Eloquent\ContractBasedPopulationCategory;
use App\Modules\Reference\Infrastructure\Persistence\Eloquent\MonthlyCadreCategory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/**
 * S40 — Monthly Workforce Multi-Value Dimensions Foundation
 * (docs/monthly-workforce-multi-value-dimensions-specification.md): the S37 canonical monthly population enriched
 * with relationship-local category / contract / job title / specialty segments and as-of reference mappings.
 * Real PostgreSQL, synthetic data only. The reporting month is November 2026: [2026-11-01, 2026-12-01).
 */
class MonthlyWorkforceDimensionsFoundationTest extends HumanResourcesTestCase
{
    private const M = '2026-11-01';

    private const N = '2026-12-01';

    /** The original S40 guard pattern, unchanged: R1/R2/R4/R5 report names. */
    private const R_REPORT_NAME_PATTERN = '/(HumanCadre|AdministrativeReport|SupportServicesReport|VolunteersReport|UnemploymentReport|Report[1245]\\b|R[1245]Report)/i';

    /** S41 (R1-D51): the only app/ files allowed to carry a HumanCadre name — exact repository-relative paths. */
    private const S41_AUTHORIZED_HUMAN_CADRE_FILES = [
        'app/Modules/HumanResources/Application/Queries/Reporting/BuildHumanCadreResult.php',
        'app/Modules/HumanResources/Application/Queries/Reporting/HumanCadrePersonRecord.php',
        'app/Modules/HumanResources/Application/Queries/Reporting/HumanCadreResult.php',
        'app/Modules/HumanResources/Presentation/Http/Controllers/HumanCadreController.php',
        'app/Modules/HumanResources/Presentation/Http/Resources/HumanCadreResource.php',
    ];

    /** S42: the only app/ files allowed to carry an AdministrativeReport name (the R2 report) — exact repository-relative paths. */
    private const S42_AUTHORIZED_ADMINISTRATIVE_REPORT_FILES = [
        'app/Modules/HumanResources/Application/Queries/Reporting/AdministrativeReportPersonRecord.php',
        'app/Modules/HumanResources/Application/Queries/Reporting/AdministrativeReportResult.php',
        'app/Modules/HumanResources/Application/Queries/Reporting/BuildAdministrativeReportResult.php',
        'app/Modules/HumanResources/Presentation/Http/Controllers/AdministrativeReportController.php',
        'app/Modules/HumanResources/Presentation/Http/Resources/AdministrativeReportResource.php',
    ];

    private const CODE = 'app/Modules/HumanResources/Application/Queries/Reporting/ListMonthlyWorkforceDimensions.php';

    // ------------------------------------------------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------------------------------------------------

    private function dims(?array $personIds = null, string $month = self::M): MonthlyWorkforceDimensions
    {
        return app(ListMonthlyWorkforceDimensions::class)($month, $personIds);
    }

    /** @return array{0: Person, 1: EmploymentRelationship} */
    private function emp(string $type = 'permanent', string $from = '2026-01-01', ?Person $person = null): array
    {
        $person ??= $this->createPersonRecord();

        return [$person, $this->createEmploymentRelationship($person, $type, null, $from)];
    }

    private function end(Person $person, EmploymentRelationship $rel, string $to): void
    {
        $rel->refresh();
        app(EndEmploymentRelationship::class)->handle($person, $rel, $rel->version, $to, false);
    }

    private function personRow(MonthlyWorkforceDimensions $result, Person $person): MonthlyDimensionPersonRow
    {
        $rows = array_values(array_filter($result->persons, fn (MonthlyDimensionPersonRow $row) => $row->personId === $person->id));
        $this->assertCount(1, $rows, 'exactly one row per Person');

        return $rows[0];
    }

    private function relOf(Person $person, int $i = 0, string $month = self::M): MonthlyDimensionRelationship
    {
        return $this->personRow($this->dims([$person->id], $month), $person)->relationships[$i];
    }

    /** @return list<array{0: string, 1: string, 2: string}> */
    private function shape(array $segments): array
    {
        return array_map(fn (array $s) => [$s['from'], $s['to'], $s['state']], $segments);
    }

    private function raw(string $stream, EmploymentRelationship $rel, string $valueId, string $from, ?string $to, array $extra = []): string
    {
        [$table, $column] = [
            'category' => ['hr.employment_category_periods', 'employment_category_id'],
            'contract' => ['hr.employment_contract_periods', 'contract_type_id'],
            'job_title' => ['hr.employment_job_title_periods', 'job_title_id'],
            'specialty' => ['hr.employment_specialty_periods', 'specialty_id'],
        ][$stream];
        $row = ['id' => (string) Str::uuid7(), 'employment_relationship_id' => $rel->id, $column => $valueId, 'effective_from' => $from, 'effective_to' => $to, 'created_at' => now()];
        if ($stream === 'contract') {
            $row['contractual_effective_to'] = $to;
        }
        DB::table($table)->insert($row + $extra);

        return $row['id'];
    }

    private function cadre(string $code): MonthlyCadreCategory
    {
        return MonthlyCadreCategory::query()->where('code', $code)->firstOrFail();
    }

    private function populationCategory(string $code): ContractBasedPopulationCategory
    {
        return ContractBasedPopulationCategory::query()->where('code', $code)->firstOrFail();
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
    // A. Month boundaries
    // ------------------------------------------------------------------------------------------------------------

    public function test_a_period_starting_exactly_at_the_month_start_covers_the_whole_month(): void
    {
        [$person, $rel] = $this->emp();
        $this->raw('category', $rel, $this->createSyntheticEmploymentCategory()->id, self::M, null);

        $this->assertSame([[self::M, self::N, 'RESOLVED']], $this->shape($this->relOf($person)->categorySegments));
    }

    public function test_a_period_ending_exactly_at_the_month_start_is_excluded(): void
    {
        [$person, $rel] = $this->emp();
        $this->raw('category', $rel, $this->createSyntheticEmploymentCategory()->id, '2026-01-01', self::M);

        $this->assertSame([[self::M, self::N, 'NOT_RECORDED']], $this->shape($this->relOf($person)->categorySegments), 'half-open: [.., M1) does not touch the month');
    }

    public function test_a_period_starting_exactly_at_the_next_month_start_is_excluded(): void
    {
        [$person, $rel] = $this->emp();
        $this->raw('category', $rel, $this->createSyntheticEmploymentCategory()->id, self::N, null);

        $this->assertSame([[self::M, self::N, 'NOT_RECORDED']], $this->shape($this->relOf($person)->categorySegments));
    }

    public function test_a_period_ending_exactly_at_the_next_month_start_is_included_through_the_last_day(): void
    {
        [$person, $rel] = $this->emp();
        $this->raw('category', $rel, $this->createSyntheticEmploymentCategory()->id, '2026-06-01', self::N);

        $this->assertSame([[self::M, self::N, 'RESOLVED']], $this->shape($this->relOf($person)->categorySegments));
    }

    // ------------------------------------------------------------------------------------------------------------
    // B. Clipping
    // ------------------------------------------------------------------------------------------------------------

    public function test_segments_are_clipped_to_the_month_and_keep_the_original_period_as_provenance(): void
    {
        [$person, $rel] = $this->emp();
        $this->raw('category', $rel, $this->createSyntheticEmploymentCategory()->id, '2026-10-15', '2027-01-15');

        $segment = $this->relOf($person)->categorySegments[0];
        $this->assertSame([self::M, self::N, 'RESOLVED'], [$segment['from'], $segment['to'], $segment['state']]);
        $this->assertSame(['2026-10-15', '2027-01-15'], [$segment['effective_from'], $segment['effective_to']], 'the recorded period is never rewritten');
    }

    public function test_segments_are_clipped_to_the_relationship_window_inside_the_month(): void
    {
        [$starting, $startingRel] = $this->emp('permanent', '2026-11-10');
        $this->raw('category', $startingRel, $this->createSyntheticEmploymentCategory()->id, '2026-11-10', null);
        [$ending, $endingRel] = $this->emp();
        app(RecordEmploymentCategoryPeriod::class)->handle($endingRel, $this->employmentCategory('grade_3'), '2026-01-01');
        $this->end($ending, $endingRel, '2026-11-20');

        $a = $this->relOf($starting);
        $this->assertSame(['2026-11-10', self::N], [$a->clippedFrom, $a->clippedTo]);
        $this->assertSame([['2026-11-10', self::N, 'RESOLVED']], $this->shape($a->categorySegments));

        $b = $this->relOf($ending);
        $this->assertSame([self::M, '2026-11-20'], [$b->clippedFrom, $b->clippedTo]);
        $this->assertSame([[self::M, '2026-11-20', 'RESOLVED']], $this->shape($b->categorySegments), 'closed at the relationship end, never beyond it');
    }

    public function test_a_relationship_starting_mid_month_has_no_segment_before_its_start(): void
    {
        [$person] = $this->emp('permanent', '2026-11-10');

        $this->assertSame([['2026-11-10', self::N, 'NOT_RECORDED']], $this->shape($this->relOf($person)->specialtySegments), 'nothing is invented before the relationship exists');
    }

    // ------------------------------------------------------------------------------------------------------------
    // C–E. One value, a mid-month change, several changes
    // ------------------------------------------------------------------------------------------------------------

    public function test_one_value_for_the_whole_month_is_one_resolved_segment_with_stable_codes(): void
    {
        [$person, $rel] = $this->emp();
        $title = $this->createSyntheticJobTitle();
        app(RecordEmploymentJobTitlePeriod::class)->handle($rel, $title, '2026-01-01');

        $segments = $this->relOf($person)->jobTitleSegments;
        $this->assertCount(1, $segments);
        $this->assertSame('RESOLVED', $segments[0]['state']);
        $this->assertSame([$title->id, $title->code], [$segments[0]['value']['id'], $segments[0]['value']['code']]);
    }

    public function test_a_mid_month_change_yields_two_segments_that_touch_without_overlap(): void
    {
        [$person, $rel] = $this->emp();
        $three = $this->employmentCategory('grade_3');
        $four = $this->employmentCategory('grade_4');
        app(RecordEmploymentCategoryPeriod::class)->handle($rel, $three, '2026-01-01');
        app(RecordEmploymentCategoryPeriod::class)->handle($rel, $four, '2026-11-16');

        $segments = $this->relOf($person)->categorySegments;
        $this->assertSame([[self::M, '2026-11-16', 'RESOLVED'], ['2026-11-16', self::N, 'RESOLVED']], $this->shape($segments));
        $this->assertSame([$three->id, $four->id], array_map(fn ($s) => $s['value']['id'], $segments), 'no scalar monthly value: both history values stay');
    }

    public function test_several_changes_in_one_month_are_all_exposed_in_chronological_order(): void
    {
        [$person, $rel] = $this->emp();
        $specialties = [$this->createSyntheticSpecialty(), $this->createSyntheticSpecialty(), $this->createSyntheticSpecialty()];
        app(RecordEmploymentSpecialtyPeriod::class)->handle($rel, $specialties[0], '2026-01-01');
        app(RecordEmploymentSpecialtyPeriod::class)->handle($rel, $specialties[1], '2026-11-10');
        app(RecordEmploymentSpecialtyPeriod::class)->handle($rel, $specialties[2], '2026-11-20');

        $segments = $this->relOf($person)->specialtySegments;
        $this->assertSame([[self::M, '2026-11-10', 'RESOLVED'], ['2026-11-10', '2026-11-20', 'RESOLVED'], ['2026-11-20', self::N, 'RESOLVED']], $this->shape($segments));
        $this->assertSame(array_map(fn ($s) => $s->id, $specialties), array_map(fn ($s) => $s['value']['id'], $segments));
    }

    // ------------------------------------------------------------------------------------------------------------
    // F–I. Gaps, NOT_APPLICABLE, contract semantics
    // ------------------------------------------------------------------------------------------------------------

    public function test_a_gap_is_not_recorded_and_is_never_filled_or_extrapolated(): void
    {
        [$person, $rel] = $this->emp();
        $title = $this->createSyntheticJobTitle();
        $this->raw('job_title', $rel, $title->id, '2026-01-01', '2026-11-10');
        $this->raw('job_title', $rel, $title->id, '2026-11-20', null);

        $this->assertSame(
            [[self::M, '2026-11-10', 'RESOLVED'], ['2026-11-10', '2026-11-20', 'NOT_RECORDED'], ['2026-11-20', self::N, 'RESOLVED']],
            $this->shape($this->relOf($person)->jobTitleSegments),
            'the same value on both sides does not close the gap',
        );
    }

    public function test_a_relationship_without_any_recorded_period_is_one_not_recorded_segment_per_applicable_dimension(): void
    {
        [$person] = $this->emp('contract');

        $rel = $this->relOf($person);
        foreach (['categorySegments', 'contractSegments', 'jobTitleSegments', 'specialtySegments'] as $dimension) {
            $this->assertSame([[self::M, self::N, 'NOT_RECORDED']], $this->shape($rel->$dimension), $dimension);
            $this->assertNull($rel->$dimension[0]['value']);
        }
    }

    public function test_the_contract_dimension_is_not_applicable_on_a_non_contract_relationship_and_never_not_recorded(): void
    {
        [$person] = $this->emp('permanent');

        $rel = $this->relOf($person);
        $this->assertSame('PERMANENT', $rel->employeeNumberScheme);
        $this->assertSame([[self::M, self::N, 'NOT_APPLICABLE']], $this->shape($rel->contractSegments));
        $this->assertNull($rel->contractSegments[0]['population_category']);
        $this->assertSame('NOT_RECORDED', $rel->categorySegments[0]['state'], 'the other dimensions stay applicable');
    }

    public function test_a_contract_expiry_while_employment_continues_is_a_not_recorded_gap(): void
    {
        [$person, $rel] = $this->emp('contract');
        app(RecordEmploymentContractPeriod::class)->handle($rel, $this->createSyntheticContractType(), '2026-01-01', '2026-11-15');

        $this->assertSame([[self::M, '2026-11-15', 'RESOLVED'], ['2026-11-15', self::N, 'NOT_RECORDED']], $this->shape($this->relOf($person)->contractSegments), 'expiry is not auto-renewed and not NOT_APPLICABLE');
    }

    public function test_the_contractual_term_end_is_preserved_separately_from_the_actual_validity(): void
    {
        [$person, $rel] = $this->emp('contract');
        $first = $this->createSyntheticContractType();
        $second = $this->createSyntheticContractType();
        app(RecordEmploymentContractPeriod::class)->handle($rel, $first, '2026-01-01', '2027-01-01');
        app(RecordEmploymentContractPeriod::class)->handle($rel, $second, '2026-11-10', '2027-11-10');

        $segments = $this->relOf($person)->contractSegments;
        $this->assertSame([[self::M, '2026-11-10', 'RESOLVED'], ['2026-11-10', self::N, 'RESOLVED']], $this->shape($segments));
        $this->assertSame(['2026-11-10', '2027-01-01'], [$segments[0]['effective_to'], $segments[0]['contractual_effective_to']], 'the renewal closed the actual validity early; the agreed term is untouched');
        $this->assertSame(['2027-11-10', '2027-11-10'], [$segments[1]['effective_to'], $segments[1]['contractual_effective_to']]);
        $this->assertSame('KNOWN', $segments[0]['contract_end_knowledge_state']);
    }

    public function test_the_contractual_term_end_is_not_equated_with_the_relationship_end(): void
    {
        [$person, $rel] = $this->emp('contract');
        app(RecordEmploymentContractPeriod::class)->handle($rel, $this->createSyntheticContractType(), '2026-01-01', '2027-06-01');
        $this->end($person, $rel, '2026-11-20');

        $relationship = $this->relOf($person);
        $this->assertSame([[self::M, '2026-11-20', 'RESOLVED']], $this->shape($relationship->contractSegments), 'actual validity closed at the relationship end');
        $this->assertSame('2027-06-01', $relationship->contractSegments[0]['contractual_effective_to'], 'the agreed term is a different fact');
        $this->assertSame('2026-11-20', $relationship->contractSegments[0]['effective_to']);
    }

    // ------------------------------------------------------------------------------------------------------------
    // J. UNKNOWN_LEGACY job-title start
    // ------------------------------------------------------------------------------------------------------------

    public function test_an_unknown_legacy_job_title_start_stays_visible_and_is_never_claimed_as_the_true_start(): void
    {
        [$person, $rel] = $this->emp();
        $title = $this->createSyntheticJobTitle();
        $this->raw('job_title', $rel, $title->id, '2026-11-12', null, ['start_knowledge_state' => 'UNKNOWN_LEGACY']);
        $known = $this->createSyntheticJobTitle();
        [$other, $otherRel] = $this->emp();
        app(RecordEmploymentJobTitlePeriod::class)->handle($otherRel, $known, '2026-01-01');

        $segments = $this->relOf($person)->jobTitleSegments;
        $this->assertSame([[self::M, '2026-11-12', 'NOT_RECORDED'], ['2026-11-12', self::N, 'RESOLVED']], $this->shape($segments), 'nothing is back-filled before the evidence boundary');
        $this->assertSame('UNKNOWN_LEGACY', $segments[1]['start_knowledge_state']);
        $this->assertSame('KNOWN', $this->relOf($other)->jobTitleSegments[0]['start_knowledge_state']);
    }

    // ------------------------------------------------------------------------------------------------------------
    // K–L. Temporal mappings (as-of each sub-interval; UNMAPPED explicit; never "other")
    // ------------------------------------------------------------------------------------------------------------

    public function test_a_specialty_cadre_mapping_that_changes_mid_month_cuts_the_segment_and_resolves_each_part_as_of(): void
    {
        [$person, $rel] = $this->emp();
        $specialty = $this->createSyntheticSpecialty();
        app(DefineSpecialtyCadreCategoryMappingPeriod::class)->handle($specialty, $this->cadre('doctors'), '2026-01-01', '2026-11-15');
        app(DefineSpecialtyCadreCategoryMappingPeriod::class)->handle($specialty, $this->cadre('nursing'), '2026-11-15', null);
        app(RecordEmploymentSpecialtyPeriod::class)->handle($rel, $specialty, '2026-01-01');

        $segments = $this->relOf($person)->specialtySegments;
        $this->assertSame([[self::M, '2026-11-15', 'RESOLVED'], ['2026-11-15', self::N, 'RESOLVED']], $this->shape($segments));
        $this->assertSame(['RESOLVED', 'RESOLVED'], array_map(fn ($s) => $s['cadre_category']['state'], $segments));
        $this->assertSame(['doctors', 'nursing'], array_map(fn ($s) => $s['cadre_category']['target']['code'], $segments), 'never today\'s mapping for the whole month');
        $this->assertSame($specialty->id, $segments[0]['value']['id'], 'one dimension value, two mapping sub-intervals');
        $this->assertSame($segments[0]['period_id'], $segments[1]['period_id']);
    }

    public function test_a_job_title_administrator_classification_changing_mid_month_is_resolved_per_sub_interval(): void
    {
        [$person, $rel] = $this->emp();
        $title = $this->createSyntheticJobTitle();
        app(DefineJobTitleAdministratorClassificationPeriod::class)->handle($title, false, '2026-01-01', '2026-11-15');
        app(DefineJobTitleAdministratorClassificationPeriod::class)->handle($title, true, '2026-11-15', null);
        app(RecordEmploymentJobTitlePeriod::class)->handle($rel, $title, '2026-01-01');

        $segments = $this->relOf($person)->jobTitleSegments;
        $this->assertSame([false, true], array_map(fn ($s) => $s['administrator_classification']['is_administrator'], $segments), 'false is a resolved classification, not unmapped');
        $this->assertSame(['RESOLVED', 'RESOLVED'], array_map(fn ($s) => $s['administrator_classification']['state'], $segments));
    }

    public function test_a_contract_type_population_mapping_is_resolved_as_of_and_a_missing_start_is_unmapped(): void
    {
        [$person, $rel] = $this->emp('contract');
        $type = $this->createSyntheticContractType();
        app(DefineContractTypePopulationMappingPeriod::class)->handle($type, $this->populationCategory('support_services_daily_worker'), '2026-11-10', null);
        app(RecordEmploymentContractPeriod::class)->handle($rel, $type, '2026-01-01', '2027-01-01');

        $segments = $this->relOf($person)->contractSegments;
        $this->assertSame([[self::M, '2026-11-10', 'RESOLVED'], ['2026-11-10', self::N, 'RESOLVED']], $this->shape($segments));
        $this->assertSame(['UNMAPPED', 'RESOLVED'], array_map(fn ($s) => $s['population_category']['state'], $segments));
        $this->assertSame('support_services_daily_worker', $segments[1]['population_category']['target']['code']);
    }

    public function test_an_unmapped_value_stays_explicitly_unmapped_and_never_becomes_other(): void
    {
        [$person, $rel] = $this->emp();
        $specialty = $this->createSyntheticSpecialty();
        app(RecordEmploymentSpecialtyPeriod::class)->handle($rel, $specialty, '2026-01-01');
        $title = $this->createSyntheticJobTitle();
        app(RecordEmploymentJobTitlePeriod::class)->handle($rel, $title, '2026-01-01');

        $cadre = $this->relOf($person)->specialtySegments[0]['cadre_category'];
        $this->assertSame('UNMAPPED', $cadre['state']);
        $this->assertNull($cadre['mapping_id']);
        $this->assertArrayNotHasKey('target', $cadre, 'no target, in particular no "other"');
        $administrator = $this->relOf($person)->jobTitleSegments[0]['administrator_classification'];
        $this->assertSame('UNMAPPED', $administrator['state']);
        $this->assertArrayNotHasKey('is_administrator', $administrator, 'an unmapped title is neither administrator nor not');
        $this->assertStringNotContainsString('other', json_encode($cadre));
    }

    public function test_a_mapping_ending_exactly_at_the_month_start_does_not_apply(): void
    {
        [$person, $rel] = $this->emp();
        $specialty = $this->createSyntheticSpecialty();
        app(DefineSpecialtyCadreCategoryMappingPeriod::class)->handle($specialty, $this->cadre('doctors'), '2026-01-01', self::M);
        app(RecordEmploymentSpecialtyPeriod::class)->handle($rel, $specialty, '2026-01-01');

        $this->assertSame('UNMAPPED', $this->relOf($person)->specialtySegments[0]['cadre_category']['state']);
    }

    public function test_only_the_dimension_value_s_own_mapping_is_used(): void
    {
        [$person, $rel] = $this->emp();
        $mapped = $this->createSyntheticSpecialty();
        $other = $this->createSyntheticSpecialty();
        app(DefineSpecialtyCadreCategoryMappingPeriod::class)->handle($other, $this->cadre('pharmacy'), '2026-01-01', null);
        app(RecordEmploymentSpecialtyPeriod::class)->handle($rel, $mapped, '2026-01-01');

        $this->assertSame('UNMAPPED', $this->relOf($person)->specialtySegments[0]['cadre_category']['state'], 'a mapping of another specialty never leaks');
    }

    // ------------------------------------------------------------------------------------------------------------
    // Labels
    // ------------------------------------------------------------------------------------------------------------

    public function test_labels_are_current_recorded_labels_and_never_persisted(): void
    {
        [$person, $rel] = $this->emp();
        $specialty = $this->createSyntheticSpecialty();
        app(RecordEmploymentSpecialtyPeriod::class)->handle($rel, $specialty, '2026-01-01');
        $periodColumns = array_map(fn ($c) => $c->column_name, DB::select("SELECT column_name FROM information_schema.columns WHERE table_schema = 'hr' AND table_name = 'employment_specialty_periods'"));

        $this->assertSame('CURRENT_RECORDED_LABEL', $this->dims([$person->id])->labelSemantics);
        $first = $this->relOf($person)->specialtySegments[0]['value'];
        $this->assertSame([$specialty->code, $specialty->name_ar, 'CURRENT_RECORDED_LABEL'], [$first['code'], $first['name_ar'], $first['label_semantics']]);

        $specialty->name_ar = 'اسم محدث';
        $specialty->save();
        $this->assertSame('اسم محدث', $this->relOf($person)->specialtySegments[0]['value']['name_ar'], 'a label change is reflected: it is the current value, not a snapshot');
        $this->assertNotContains('name_ar', $periodColumns, 'the period row stores no label copy');
    }

    // ------------------------------------------------------------------------------------------------------------
    // M–P. Relationships, reappointment, Person uniqueness, no leakage
    // ------------------------------------------------------------------------------------------------------------

    public function test_one_person_with_two_relationships_keeps_each_history_local_and_stays_one_row(): void
    {
        [$person, $old] = $this->emp('contract');
        $oldSpecialty = $this->createSyntheticSpecialty();
        $newSpecialty = $this->createSyntheticSpecialty();
        app(RecordEmploymentSpecialtyPeriod::class)->handle($old, $oldSpecialty, '2026-01-01');
        $this->end($person, $old, '2026-11-10');
        $new = $this->createEmploymentRelationship($person, 'contract', null, '2026-11-10');
        app(RecordEmploymentSpecialtyPeriod::class)->handle($new, $newSpecialty, '2026-11-10');
        app(RecordEmploymentCategoryPeriod::class)->handle($new, $this->employmentCategory('grade_3'), '2026-11-10');

        $row = $this->personRow($this->dims([$person->id]), $person);
        $this->assertSame([$old->id, $new->id], array_map(fn ($r) => $r->employmentRelationshipId, $row->relationships), 'same order as the S37 relationship segments');
        [$a, $b] = $row->relationships;
        $this->assertSame([[self::M, '2026-11-10', 'RESOLVED']], $this->shape($a->specialtySegments));
        $this->assertSame($oldSpecialty->id, $a->specialtySegments[0]['value']['id']);
        $this->assertSame([['2026-11-10', self::N, 'RESOLVED']], $this->shape($b->specialtySegments));
        $this->assertSame($newSpecialty->id, $b->specialtySegments[0]['value']['id'], 'no fact leaks across relationships');
        $this->assertSame([[self::M, '2026-11-10', 'NOT_RECORDED']], $this->shape($a->categorySegments), 'the new relationship\'s category never reaches the old one');
        $this->assertSame([['2026-11-10', self::N, 'RESOLVED']], $this->shape($b->categorySegments));
    }

    public function test_the_person_set_and_order_are_exactly_the_canonical_s37_population(): void
    {
        for ($i = 0; $i < 4; $i++) {
            [$person, $rel] = $this->emp($i % 2 === 0 ? 'contract' : 'permanent');
            app(RecordEmploymentSpecialtyPeriod::class)->handle($rel, $this->createSyntheticSpecialty(), '2026-01-01');
            app(RecordEmploymentSpecialtyPeriod::class)->handle($rel, $this->createSyntheticSpecialty(), '2026-11-10');
        }

        $canonical = app(ListMonthlyReportingPopulation::class)(self::M);
        $enriched = $this->dims();

        $ids = array_map(fn ($row) => $row->personId, $enriched->persons);
        $this->assertSame(array_map(fn ($row) => $row->personId, $canonical->persons), $ids, 'same Persons, same order');
        $this->assertSame($ids, array_values(array_unique($ids)), 'no Person is duplicated by a dimension change');
        $this->assertSame(
            array_map(fn ($row) => array_map(fn ($s) => [$s->employmentRelationshipId, $s->clippedFrom, $s->clippedTo], $row->relationships), $canonical->persons),
            array_map(fn ($row) => array_map(fn ($r) => [$r->employmentRelationshipId, $r->clippedFrom, $r->clippedTo], $row->relationships), $enriched->persons),
            'the relationship windows are S37\'s own',
        );
    }

    public function test_every_segment_list_tiles_the_relationship_window_exactly(): void
    {
        [$person, $rel] = $this->emp();
        app(RecordEmploymentCategoryPeriod::class)->handle($rel, $this->employmentCategory('grade_3'), '2026-01-01');
        app(RecordEmploymentCategoryPeriod::class)->handle($rel, $this->employmentCategory('grade_4'), '2026-11-16');
        $this->raw('job_title', $rel, $this->createSyntheticJobTitle()->id, '2026-11-05', '2026-11-09');

        foreach ($this->dims()->persons as $row) {
            foreach ($row->relationships as $relationship) {
                foreach (['categorySegments', 'contractSegments', 'jobTitleSegments', 'specialtySegments'] as $dimension) {
                    $cursor = $relationship->clippedFrom;
                    foreach ($relationship->$dimension as $segment) {
                        $this->assertSame($cursor, $segment['from'], "{$dimension}: contiguous");
                        $this->assertLessThan($segment['to'], $segment['from'], "{$dimension}: non-empty half-open");
                        $cursor = $segment['to'];
                    }
                    $this->assertSame($relationship->clippedTo, $cursor, "{$dimension}: tiles the window");
                }
            }
        }
        $this->assertNotEmpty($person->id);
    }

    public function test_the_person_restriction_is_forwarded_to_the_canonical_population(): void
    {
        [$a] = $this->emp();
        $this->emp();

        $this->assertSame([$a->id], array_map(fn ($row) => $row->personId, $this->dims([$a->id])->persons));
        $this->assertSame([], $this->dims([])->persons);
        $this->assertSame([], $this->dims(null, '1990-01-01')->persons);
    }

    // ------------------------------------------------------------------------------------------------------------
    // Explicit failure on corrupt history
    // ------------------------------------------------------------------------------------------------------------

    public function test_overlapping_periods_fail_explicitly_and_never_pick_a_value(): void
    {
        $periods = [
            ['id' => 'a', 'effective_from' => '2026-11-01', 'effective_to' => '2026-11-20'],
            ['id' => 'b', 'effective_from' => '2026-11-10', 'effective_to' => null],
        ];

        $this->expectException(InconsistentDimensionHistoryException::class);
        MonthlyDimensionSegmentation::segments(self::M, self::N, $periods, true);
    }

    public function test_an_open_period_followed_by_another_fails_explicitly(): void
    {
        $this->expectException(InconsistentDimensionHistoryException::class);
        MonthlyDimensionSegmentation::segments(self::M, self::N, [
            ['id' => 'a', 'effective_from' => '2026-10-01', 'effective_to' => null],
            ['id' => 'b', 'effective_from' => '2026-11-10', 'effective_to' => null],
        ], true);
    }

    public function test_overlapping_mappings_fail_explicitly(): void
    {
        $segments = MonthlyDimensionSegmentation::segments(self::M, self::N, [['id' => 'p', 'effective_from' => '2026-01-01', 'effective_to' => null, 'value_id' => 'v']], true);

        $this->expectException(InconsistentDimensionHistoryException::class);
        MonthlyDimensionSegmentation::mapped($segments, ['v' => [
            ['id' => 'm1', 'effective_from' => '2026-01-01', 'effective_to' => '2026-11-20'],
            ['id' => 'm2', 'effective_from' => '2026-11-10', 'effective_to' => null],
        ]], 'value_id');
    }

    public function test_a_contract_recorded_where_the_contract_dimension_does_not_apply_fails_explicitly(): void
    {
        [$person, $rel] = $this->emp('permanent');
        $this->raw('contract', $rel, $this->createSyntheticContractType()->id, '2026-01-01', '2027-01-01', ['contract_end_knowledge_state' => 'KNOWN']);

        $this->expectException(InconsistentDimensionHistoryException::class);
        $this->dims([$person->id]);
    }

    public function test_the_pure_segmentation_has_no_scalar_collapse_and_does_not_fill_gaps(): void
    {
        $segments = MonthlyDimensionSegmentation::segments(self::M, self::N, [
            ['id' => 'a', 'effective_from' => '2026-01-01', 'effective_to' => '2026-11-10', 'value_id' => 'v'],
            ['id' => 'b', 'effective_from' => '2026-11-20', 'effective_to' => null, 'value_id' => 'v'],
        ], true);

        $this->assertSame(['RESOLVED', 'NOT_RECORDED', 'RESOLVED'], array_column($segments, 'state'));
        $this->assertCount(3, $segments);
    }

    // ------------------------------------------------------------------------------------------------------------
    // Q–T. Canonical reuse, query count, no N+1
    // ------------------------------------------------------------------------------------------------------------

    public function test_the_canonical_s37_population_is_computed_exactly_once(): void
    {
        [, $rel] = $this->emp();
        app(RecordEmploymentSpecialtyPeriod::class)->handle($rel, $this->createSyntheticSpecialty(), '2026-01-01');

        $statements = $this->statements(fn () => $this->dims());

        $this->assertCount(1, array_filter($statements, fn ($sql) => str_contains($sql, 'SELECT r.id, r.person_id, r.employment_type_id')), 'one S37 computation');
        $this->assertCount(1, array_filter($statements, fn ($sql) => str_contains($sql, 'FROM hr.employment_status_periods sp')), 'S40 reads no status of its own');
    }

    public function test_s40_contains_no_population_or_classification_logic_of_its_own(): void
    {
        $code = preg_replace('#/\*.*?\*/|//[^\n]*#s', '', (string) file_get_contents(base_path(self::CODE)));

        $this->assertSame(1, substr_count($code, '($this->population)('), 'ListMonthlyReportingPopulation is called exactly once');
        foreach (['hr.employment_relationships', 'hr.persons', 'hr.employment_status_periods', 'organizational_placement', 'full_secondment', 'workplace_assignment', 'partial_secondment', 'work_schedule', 'MonthlyStatusSegmentation', 'MonthlyDutyClassification', 'MonthlyWorkplaceSegmentation', 'ListReportingPopulationAsOf', 'ResolveEmployment'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $code, "S40 re-derives nothing: {$forbidden}");
        }
        foreach (['ListMonthlyReportingPopulation', 'MonthlyReportingPersonRow', 'MonthlyRelationshipSegment'] as $dto) {
            $this->assertStringNotContainsString('new '.$dto, $code);
        }
    }

    public function test_the_statement_count_is_constant_and_independent_of_population_size(): void
    {
        $measure = function (int $people): array {
            for ($i = 0; $i < $people; $i++) {
                [, $rel] = $this->emp('contract');
                $specialty = $this->createSyntheticSpecialty();
                $title = $this->createSyntheticJobTitle();
                $type = $this->createSyntheticContractType();
                app(DefineSpecialtyCadreCategoryMappingPeriod::class)->handle($specialty, $this->cadre('doctors'), '2026-01-01', '2026-11-15');
                app(DefineJobTitleAdministratorClassificationPeriod::class)->handle($title, true, '2026-01-01', null);
                app(DefineContractTypePopulationMappingPeriod::class)->handle($type, $this->populationCategory('support_services_daily_worker'), '2026-01-01', null);
                app(RecordEmploymentSpecialtyPeriod::class)->handle($rel, $specialty, '2026-01-01');
                app(RecordEmploymentSpecialtyPeriod::class)->handle($rel, $this->createSyntheticSpecialty(), '2026-11-10');
                app(RecordEmploymentJobTitlePeriod::class)->handle($rel, $title, '2026-01-01');
                app(RecordEmploymentContractPeriod::class)->handle($rel, $type, '2026-01-01', '2027-01-01');
                app(RecordEmploymentCategoryPeriod::class)->handle($rel, $this->employmentCategory('grade_3'), '2026-01-01');
            }

            return $this->statements(fn () => $this->dims());
        };

        $small = $measure(2);
        $large = $measure(14);

        $this->assertCount(15, $small, 'S37\'s 8 + 4 streams + 3 mappings');
        $this->assertCount(count($small), $large, 'no N+1: the statement count does not depend on Person, relationship or segment count');
        foreach ($large as $sql) {
            $this->assertStringStartsWith('select', strtolower(ltrim($sql)), 'read-only: every statement is a SELECT');
        }
    }

    public function test_every_dimension_and_mapping_lookup_is_one_bounded_batch(): void
    {
        [, $rel] = $this->emp('contract');
        app(RecordEmploymentCategoryPeriod::class)->handle($rel, $this->employmentCategory('grade_3'), '2026-01-01');

        $statements = $this->statements(fn () => $this->dims());

        foreach ([
            'FROM hr.employment_category_periods p', 'FROM hr.employment_contract_periods p', 'FROM hr.employment_job_title_periods p', 'FROM hr.employment_specialty_periods p',
            'FROM ref.specialty_cadre_category_mappings m', 'FROM ref.job_title_administrator_classifications m', 'FROM ref.contract_type_population_mappings m',
        ] as $needle) {
            $matching = array_filter($statements, fn ($sql) => str_contains($sql, $needle));
            $this->assertCount(1, $matching, "one batch: {$needle}");
            $this->assertStringContainsString('= ANY(CAST(? AS uuid[]))', reset($matching), 'bounded by an id set');
        }
    }

    public function test_the_empty_month_returns_no_rows_without_extra_work(): void
    {
        $result = $this->dims(null, '1990-01-01');

        $this->assertSame([], $result->persons);
        $this->assertSame('CURRENT_RECORDED_LABEL', $result->labelSemantics);
    }

    public function test_the_read_model_is_read_only_and_persists_nothing(): void
    {
        [, $rel] = $this->emp();
        app(RecordEmploymentSpecialtyPeriod::class)->handle($rel, $this->createSyntheticSpecialty(), '2026-01-01');
        $tables = ['hr.employment_category_periods', 'hr.employment_contract_periods', 'hr.employment_job_title_periods', 'hr.employment_specialty_periods', 'audit.audit_entries'];
        $count = fn () => array_map(fn ($t) => DB::table($t)->count(), $tables);
        $before = $count();

        $this->dims();
        $this->dims();

        $this->assertSame($before, $count());
    }

    // ------------------------------------------------------------------------------------------------------------
    // U–W. Schema, scope and S37 protection guards
    // ------------------------------------------------------------------------------------------------------------

    public function test_s40_adds_no_schema_object(): void
    {
        // Mechanical S41 accommodation: S40 itself added no migration — the migrations before S41's (2026_10_18) are still 86, ending with S39's.
        $beforeS41 = collect(glob(base_path('database/migrations/*.php')))->map('basename')->sort()->filter(fn ($name) => $name < '2026_10_18')->values();
        $this->assertCount(86, $beforeS41, 'no S40 migration: the S39 migration was the latest before S41');
        $this->assertSame('2026_10_17_000001_seed_security_monthly_not_on_duty_permission.php', $beforeS41->last());
        $this->assertSame(0, DB::table('information_schema.tables')->whereIn('table_schema', ['hr', 'ref', 'org', 'automation', 'reporting'])->where(fn ($q) => $q->where('table_name', 'like', '%dimension%')->orWhere('table_name', 'like', '%monthly_workforce%')->orWhere('table_name', 'like', '%monthly_population%'))->count());
        $this->assertSame(0, (int) DB::selectOne('select count(*) as c from pg_matviews')->c);
        // S48 (§S48.3) adds the one view the whole application reads qualifications through going
        // forward — unrelated to S40, which still adds no view/schema object of its own.
        $this->assertSame(['person_qualifications_current'], DB::table('information_schema.views')->whereIn('table_schema', ['hr', 'ref', 'org', 'automation', 'reporting'])->pluck('table_name')->all());
    }

    public function test_s40_exposes_no_route_controller_resource_or_permission(): void
    {
        foreach (Route::getRoutes() as $route) {
            $this->assertDoesNotMatchRegularExpression('/MonthlyWorkforceDimensions|MonthlyDimension|dimension/i', $route->uri().' '.$route->getActionName(), 'S40 is internal only');
        }
        $this->assertSame([], glob(base_path('app/Modules/*/Presentation/*/*Dimension*.php')));
        foreach (HumanResourcesPermissionCatalog::ALL as $permission) {
            $this->assertStringNotContainsString('dimension', $permission);
        }
    }

    public function test_s40_adds_no_frontend_export_or_output_class_and_no_report(): void
    {
        $sources = array_merge(
            glob(base_path('../frontend/src/**/*.ts*')) ?: [],
            glob(base_path('../frontend/src/*/*/*.ts*')) ?: [],
            glob(base_path('../frontend/src/*/*.ts*')) ?: [],
        );
        foreach ($sources as $file) {
            $this->assertStringNotContainsString('MonthlyWorkforceDimensions', (string) file_get_contents($file), 'no frontend consumer');
        }

        $paths = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path('app'), \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            $paths[] = ltrim(str_replace('\\', '/', substr($file->getPathname(), strlen(base_path()))), '/');
        }

        $this->assertSame([], $this->guardViolations($paths), 'no output class and no R1/R2/R4/R5 report file outside the exact S41 allowlist');
    }

    /**
     * The S40 name guard, as a pure function over repository-relative paths: every file is checked against the output-class ban;
     * the R1/R2/R4/R5 pattern is then applied to every file EXCEPT the exact S41 allowlist paths (R1-D51).
     *
     * @param  list<string>  $paths
     * @param  list<string>|null  $allowlist  the exact-path exception (default: the S41 files)
     * @return list<string> the rejected paths
     */
    private function guardViolations(array $paths, ?array $allowlist = null): array
    {
        $allowlist ??= array_merge(self::S41_AUTHORIZED_HUMAN_CADRE_FILES, self::S42_AUTHORIZED_ADMINISTRATIVE_REPORT_FILES);
        $violations = [];
        foreach ($paths as $path) {
            $name = basename($path);
            if (preg_match('/(Xlsx|Pdf|Csv|Print|Dashboard|Export)/i', $name) === 1) {
                $violations[] = $path;

                continue;
            }
            if (in_array($path, $allowlist, true)) {
                continue; // S41 (R1-D51): an explicit, exact-path exception — never a pattern, never a wildcard
            }
            if (preg_match(self::R_REPORT_NAME_PATTERN, $name) === 1) {
                $violations[] = $path;
            }
        }

        return $violations;
    }

    /**
     * S41 (R1-D51): the guard keeps its R1/R2/R4/R5 name pattern for every file of app/ except the EXACT paths of the files the S41
     * stage authorizes for REPORT-1. The exception is an exact-path allowlist (no wildcard, no global HumanCadre exemption), the
     * output-class ban still applies to allowlisted files, and a future, unlisted or relocated HumanCadre-like file still fails.
     */
    public function test_the_s41_exception_is_an_exact_path_allowlist_and_future_human_cadre_files_still_fail(): void
    {
        foreach (self::S41_AUTHORIZED_HUMAN_CADRE_FILES as $path) {
            $this->assertStringStartsWith('app/Modules/HumanResources/', $path, 'only HumanResources REPORT-1 files');
            $this->assertStringNotContainsString('*', $path, 'no wildcard');
            $this->assertMatchesRegularExpression(self::R_REPORT_NAME_PATTERN, basename($path), 'each allowlisted file is exactly the kind the pattern forbids elsewhere');
            $this->assertFileExists(base_path($path));
        }
        $this->assertCount(5, self::S41_AUTHORIZED_HUMAN_CADRE_FILES);
        $this->assertSame([], $this->guardViolations(self::S41_AUTHORIZED_HUMAN_CADRE_FILES), 'the authorized S41 files pass');

        $reporting = 'app/Modules/HumanResources/Application/Queries/Reporting/';
        $rejected = [
            $reporting.'FutureHumanCadreSummary.php',              // unlisted HumanCadre-like file
            $reporting.'HumanCadreExportBuilder.php',               // HumanCadre + export naming
            $reporting.'BuildHumanCadreResultXlsx.php',             // an allowlist-like name with an output suffix
            'app/Modules/Other/Application/BuildHumanCadreResult.php', // an allowlisted basename at a different path
            $reporting.'HumanCadreReport2Builder.php',
            $reporting.'AdministrativeReportBuilder.php',
            $reporting.'Report5.php',
        ];
        $this->assertSame($rejected, $this->guardViolations(array_merge(self::S41_AUTHORIZED_HUMAN_CADRE_FILES, $rejected)), 'every non-allowlisted or output-named file is still rejected');
        $this->assertSame(['app/Modules/HumanResources/Presentation/Http/Controllers/HumanCadreExport.php'], $this->guardViolations(['app/Modules/HumanResources/Presentation/Http/Controllers/HumanCadreExport.php']), 'a HumanCadre export file fails even in an authorized directory');

        // The output-class ban applies to allowlisted files too: even an exact-path exception cannot admit an output class.
        $exportPath = 'app/Modules/HumanResources/Presentation/Http/Controllers/HumanCadreExportController.php';
        $this->assertSame([$exportPath], $this->guardViolations([$exportPath], [$exportPath]), 'allowlisting an export-named path does not admit it');
        $this->assertSame([], $this->guardViolations([self::S41_AUTHORIZED_HUMAN_CADRE_FILES[0]], [self::S41_AUTHORIZED_HUMAN_CADRE_FILES[0]]));
    }

    /**
     * S42: the same exact-path allowlist for the five authorized R2 files. The R1/R2/R4/R5 name pattern and the output-class ban are
     * unchanged for everything else, and a future, unlisted, relocated or export-named AdministrativeReport file still fails.
     */
    public function test_the_s42_exception_is_an_exact_path_allowlist_and_other_administrative_report_files_still_fail(): void
    {
        foreach (self::S42_AUTHORIZED_ADMINISTRATIVE_REPORT_FILES as $path) {
            $this->assertStringStartsWith('app/Modules/HumanResources/', $path);
            $this->assertStringNotContainsString('*', $path, 'no wildcard');
            $this->assertMatchesRegularExpression(self::R_REPORT_NAME_PATTERN, basename($path), 'each file is exactly the kind the pattern forbids elsewhere');
            $this->assertFileExists(base_path($path));
        }
        $this->assertCount(5, self::S42_AUTHORIZED_ADMINISTRATIVE_REPORT_FILES);
        $this->assertSame([], $this->guardViolations(self::S42_AUTHORIZED_ADMINISTRATIVE_REPORT_FILES), 'the authorized S42 files pass');

        $reporting = 'app/Modules/HumanResources/Application/Queries/Reporting/';
        $rejected = [
            $reporting.'FutureAdministrativeReportBuilder.php',
            $reporting.'AdministrativeReportExporter.php',
            $reporting.'BuildAdministrativeReportResultXlsx.php',
            'app/Modules/Other/Application/BuildAdministrativeReportResult.php',
            $reporting.'SupportServicesReportBuilder.php',
        ];
        $this->assertSame($rejected, $this->guardViolations(array_merge(self::S42_AUTHORIZED_ADMINISTRATIVE_REPORT_FILES, $rejected)));
        $exportPath = 'app/Modules/HumanResources/Presentation/Http/Controllers/AdministrativeReportExportController.php';
        $this->assertSame([$exportPath], $this->guardViolations([$exportPath], [$exportPath]), 'allowlisting an export-named path does not admit it');
    }

    public function test_s40_computes_no_total_percentage_age_or_aggregate(): void
    {
        $code = preg_replace('#/\*.*?\*/|//[^\n]*#s', '', (string) file_get_contents(base_path(self::CODE)));

        foreach (['count(', 'array_sum', 'GROUP BY', 'SUM(', 'COUNT(', 'AVG(', 'percent', 'total', 'age(', 'AGE(', 'birth_date'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $code, "S40 aggregates nothing: {$forbidden}");
        }
        foreach (['first', 'last', 'month_end', 'majority', 'current_value', 'monthly_value'] as $scalar) {
            $this->assertStringNotContainsString($scalar, strtolower($code), "no scalar monthly selection: {$scalar}");
        }
    }

    public function test_the_s37_production_files_are_untouched(): void
    {
        $this->emp();
        $source = (string) file_get_contents(base_path('app/Modules/HumanResources/Application/Queries/Reporting/ListMonthlyReportingPopulation.php'));

        $this->assertStringNotContainsString('Dimension', $source, 'S37 knows nothing of S40');
        $this->assertStringNotContainsString('employment_category_periods', $source);
        $this->assertStringNotContainsString('employment_contract_periods', $source);
        $this->assertStringNotContainsString('employment_job_title_periods', $source);
        $this->assertStringNotContainsString('employment_specialty_periods', $source);
        $this->assertCount(8, $this->statements(fn () => app(ListMonthlyReportingPopulation::class)(self::M)), 'S37 keeps its eight-statement contract');
    }
}
