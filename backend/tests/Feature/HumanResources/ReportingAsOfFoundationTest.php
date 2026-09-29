<?php

namespace Tests\Feature\HumanResources;

use App\Modules\HumanResources\Application\Commands\EndEmploymentRelationship;
use App\Modules\HumanResources\Application\Commands\EndFullSecondment;
use App\Modules\HumanResources\Application\Commands\EndWorkplaceAssignment;
use App\Modules\HumanResources\Application\Commands\RecordEmploymentCategoryPeriod;
use App\Modules\HumanResources\Application\Commands\RecordEmploymentContractPeriod;
use App\Modules\HumanResources\Application\Commands\RecordEmploymentJobTitlePeriod;
use App\Modules\HumanResources\Application\Commands\RecordEmploymentSpecialtyPeriod;
use App\Modules\HumanResources\Application\Commands\RecordEmploymentStatusPeriod;
use App\Modules\HumanResources\Application\Commands\StartFullSecondment;
use App\Modules\HumanResources\Application\Commands\StartWorkplaceAssignment;
use App\Modules\HumanResources\Application\Commands\TransferEmployee;
use App\Modules\HumanResources\Application\Queries\Reporting\ListReportingPopulationAsOf;
use App\Modules\HumanResources\Application\Queries\Reporting\ReportingPopulationRow;
use App\Modules\HumanResources\Application\Queries\ResolveActualWorkplaceForRelationship;
use App\Modules\HumanResources\Application\Queries\ResolveActualWorkplaceForRelationshipAsOf;
use App\Modules\HumanResources\Application\Queries\ResolveEmploymentCategoryForRelationshipAsOf;
use App\Modules\HumanResources\Application\Queries\ResolveEmploymentContractForRelationshipAsOf;
use App\Modules\HumanResources\Application\Queries\ResolveEmploymentJobTitleForRelationshipAsOf;
use App\Modules\HumanResources\Application\Queries\ResolveEmploymentSpecialtyForRelationshipAsOf;
use App\Modules\HumanResources\Application\Queries\ResolveEmploymentStatusForRelationshipAsOf;
use App\Modules\HumanResources\Application\Queries\ResolveOrganizationalPlacementForRelationshipAsOf;
use App\Modules\HumanResources\Domain\ActualWorkplaceAsOf;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\EmploymentRelationship;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\Person;
use App\Modules\Organization\Infrastructure\Persistence\Eloquent\OrganizationalUnit;
use App\Modules\Reference\Application\Commands\DeactivateSpecialty;
use App\Modules\Reference\Application\Commands\DefineContractTypePopulationMappingPeriod;
use App\Modules\Reference\Application\Commands\DefineJobTitleAdministratorClassificationPeriod;
use App\Modules\Reference\Application\Commands\DefineSpecialtyCadreCategoryMappingPeriod;
use App\Modules\Reference\Application\Queries\ResolveEmploymentStatusDetailBehaviorAsOf;
use App\Modules\Reference\Infrastructure\Persistence\Eloquent\ContractBasedPopulationCategory;
use App\Modules\Reference\Infrastructure\Persistence\Eloquent\EmploymentStatusDetail;
use App\Modules\Reference\Infrastructure\Persistence\Eloquent\MonthlyCadreCategory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * S27 Reporting As-Of Foundation (docs/reporting-as-of-foundation-specification.md, ADR-S27-001,
 * CA-S27-01, CA-S27-02): explicit-date as-of readers for status (with S06 behaviors), organizational
 * placement and actual workplace; the live-query reporting population; half-open boundaries;
 * current-vs-as-of semantics; historical movement-overlap ambiguity; S06 mapping reuse; the
 * unknown/unresolved policy; reappointment isolation; R1–R5 readiness (facts only, no report);
 * constant query count; and scope guards (no partial secondment, schedule, materialization or
 * output layer). All data synthetic; configuration catalogs are populated only by test fixtures.
 */
class ReportingAsOfFoundationTest extends HumanResourcesTestCase
{
    // ---------------------------------------------------------------------
    // A. Status and status behavior as-of
    // ---------------------------------------------------------------------

    public function test_status_resolves_before_at_and_after_a_transition_with_same_date_behavior(): void
    {
        [$person, $rel] = $this->personAndRelationship();
        $this->recordStatus($person, $rel, 'on_duty', '2026-09-27');
        $this->recordStatus($person, $rel, 'unpaid_leave', '2026-11-01');

        $this->assertNull($this->statusAsOf($rel, '2026-09-26'), 'no status recorded yet → UNRESOLVED');
        $this->assertSame('on_duty', $this->statusCodeAsOf($rel, '2026-10-31'));
        $this->assertSame('unpaid_leave', $this->statusCodeAsOf($rel, '2026-11-01'), 'half-open: the boundary belongs to the new status');
        $this->assertSame('unpaid_leave', $this->statusCodeAsOf($rel, '2026-12-15'));

        foreach (['2026-10-31', '2026-11-01'] as $date) {
            $row = $this->populationRow($rel, $date);
            $behavior = app(ResolveEmploymentStatusDetailBehaviorAsOf::class)(EmploymentStatusDetail::query()->findOrFail($row->statusDetailId), $date);
            $this->assertNotNull($behavior);
            $this->assertSame((bool) $behavior->participates_in_active_workforce, $row->participatesInActiveWorkforce, "behavior resolved on {$date}");
            $this->assertSame($behavior->counts_in_monthly_reporting === null ? null : (bool) $behavior->counts_in_monthly_reporting, $row->countsInMonthlyReporting);
            $this->assertSame((bool) $behavior->is_terminal, $row->isTerminal);
        }
        $this->assertNotSame($this->populationRow($rel, '2026-10-31')->participatesInActiveWorkforce, $this->populationRow($rel, '2026-11-01')->participatesInActiveWorkforce,
            'on_duty and unpaid_leave carry different frozen S06 behavior');
    }

    // ---------------------------------------------------------------------
    // B. Organizational placement and actual workplace as-of
    // ---------------------------------------------------------------------

    public function test_placement_resolves_before_at_and_after_a_transition_including_transfer(): void
    {
        [, $rel] = $this->personAndRelationship();
        $home = $this->createUnit();
        $other = $this->createUnit();
        $this->recordPlacement($rel, $home, '2026-01-15');
        app(TransferEmployee::class)->handle($rel->refresh(), $other, '2026-06-01', $this->transferDecisionType());

        $this->assertNull($this->placementUnit($rel, '2026-01-14'), 'no placement yet — never fabricated');
        $this->assertSame($home->id, $this->placementUnit($rel, '2026-01-15'));
        $this->assertSame($home->id, $this->placementUnit($rel, '2026-05-31'));
        $this->assertSame($other->id, $this->placementUnit($rel, '2026-06-01'), 'S14 transfer is reflected in placement history');
        $this->assertSame($other->id, $this->populationRow($rel, '2026-06-01')->placementOrganizationalUnitId);
    }

    public function test_full_secondment_before_during_and_after(): void
    {
        [$rel, $home] = $this->placedRelationship();
        $dest = $this->createUnit();
        app(StartFullSecondment::class)->handle($rel, $dest, '2026-03-01');
        app(EndFullSecondment::class)->handle($rel->refresh(), '2026-07-01');

        $this->assertWorkplace($rel, '2026-02-28', ActualWorkplaceAsOf::RESOLVED, $home->id, 'placement');
        $this->assertWorkplace($rel, '2026-03-01', ActualWorkplaceAsOf::RESOLVED, $dest->id, 'secondment');
        $this->assertWorkplace($rel, '2026-06-30', ActualWorkplaceAsOf::RESOLVED, $dest->id, 'secondment');
        $this->assertWorkplace($rel, '2026-07-01', ActualWorkplaceAsOf::RESOLVED, $home->id, 'placement');
        $this->assertSame($home->id, $this->placementUnit($rel, '2026-04-01'), 'original placement is never replaced by the actual workplace');
    }

    public function test_workplace_assignment_before_during_and_after(): void
    {
        [$rel, $home] = $this->placedRelationship();
        $dest = $this->createUnit();
        app(StartWorkplaceAssignment::class)->handle($rel, $dest, '2026-03-01', $this->assignmentDecisionType());
        app(EndWorkplaceAssignment::class)->handle($rel->refresh(), '2026-07-01');

        $this->assertWorkplace($rel, '2026-02-28', ActualWorkplaceAsOf::RESOLVED, $home->id, 'placement');
        $this->assertWorkplace($rel, '2026-03-01', ActualWorkplaceAsOf::RESOLVED, $dest->id, 'assignment');
        $this->assertWorkplace($rel, '2026-06-30', ActualWorkplaceAsOf::RESOLVED, $dest->id, 'assignment');
        $this->assertWorkplace($rel, '2026-07-01', ActualWorkplaceAsOf::RESOLVED, $home->id, 'placement');
    }

    public function test_relationship_before_start_during_and_after_a_known_end(): void
    {
        [$person, $rel] = $this->personAndRelationship('2026-01-01');
        $this->recordPlacement($rel, $this->createUnit(), '2026-01-15');
        $this->end($person, $rel, '2026-09-01');
        $rel->refresh();

        $this->assertWorkplace($rel, '2025-12-31', ActualWorkplaceAsOf::UNRESOLVED, null, null);
        $this->assertNull($this->populationRowOrNull($rel, '2025-12-31'), 'not yet effective → not in the population');
        $this->assertNotNull($this->populationRowOrNull($rel, '2026-08-31'));
        $this->assertWorkplace($rel, '2026-08-31', ActualWorkplaceAsOf::RESOLVED, null, 'placement');
        $this->assertNull($this->populationRowOrNull($rel, '2026-09-01'), 'KNOWN end at the date → not effective (half-open)');
        $this->assertWorkplace($rel, '2026-09-01', ActualWorkplaceAsOf::UNRESOLVED, null, null);
    }

    // ---------------------------------------------------------------------
    // C. CA-S27-01 — current (open-period) vs as-of (effective-date)
    // ---------------------------------------------------------------------

    public function test_ca_s27_01_no_future_recorded_facts_current_and_as_of_today_agree(): void
    {
        [$rel, $home] = $this->placedRelationship();
        $dest = $this->createUnit();
        app(StartFullSecondment::class)->handle($rel, $dest, '2026-03-01');
        $today = now()->toDateString();

        $current = app(ResolveActualWorkplaceForRelationship::class)($rel->refresh());
        $asOf = $this->workplace($rel, $today);

        $this->assertSame($current->organizationalUnitId(), $asOf->organizationalUnitId());
        $this->assertSame($current->source(), $asOf->source());
        $this->assertSame($current->since()?->toDateString(), $asOf->since()?->toDateString());
    }

    public function test_ca_s27_01_future_secondment_start_is_not_effective_before_it_starts(): void
    {
        [$rel, $home] = $this->placedRelationship();
        $dest = $this->createUnit();
        app(StartFullSecondment::class)->handle($rel, $dest, '2099-01-01');

        $current = app(ResolveActualWorkplaceForRelationship::class)($rel->refresh());
        $this->assertSame($dest->id, $current->organizationalUnitId(), 'the unchanged open-period view may show the recorded future destination');
        $this->assertWorkplace($rel, now()->toDateString(), ActualWorkplaceAsOf::RESOLVED, $home->id, 'placement');
        $this->assertWorkplace($rel, '2098-12-31', ActualWorkplaceAsOf::RESOLVED, $home->id, 'placement');
        $this->assertWorkplace($rel, '2099-01-01', ActualWorkplaceAsOf::RESOLVED, $dest->id, 'secondment');
    }

    public function test_ca_s27_01_future_secondment_end_stays_effective_until_it_ends(): void
    {
        [$rel, $home] = $this->placedRelationship();
        $dest = $this->createUnit();
        app(StartFullSecondment::class)->handle($rel, $dest, '2026-03-01');
        app(EndFullSecondment::class)->handle($rel->refresh(), '2099-06-01');

        $current = app(ResolveActualWorkplaceForRelationship::class)($rel->refresh());
        $this->assertSame('placement', $current->source(), 'open-period view: the secondment row is closed');
        $this->assertWorkplace($rel, now()->toDateString(), ActualWorkplaceAsOf::RESOLVED, $dest->id, 'secondment');
        $this->assertWorkplace($rel, '2099-05-31', ActualWorkplaceAsOf::RESOLVED, $dest->id, 'secondment');
        $this->assertWorkplace($rel, '2099-06-01', ActualWorkplaceAsOf::RESOLVED, $home->id, 'placement');
    }

    public function test_ca_s27_01_future_assignment_start_and_end_follow_effective_dates(): void
    {
        [$rel, $home] = $this->placedRelationship();
        $dest = $this->createUnit();
        app(StartWorkplaceAssignment::class)->handle($rel, $dest, '2099-01-01', $this->assignmentDecisionType());
        app(EndWorkplaceAssignment::class)->handle($rel->refresh(), '2099-03-01');

        $this->assertWorkplace($rel, '2098-12-31', ActualWorkplaceAsOf::RESOLVED, $home->id, 'placement');
        $this->assertWorkplace($rel, '2099-01-01', ActualWorkplaceAsOf::RESOLVED, $dest->id, 'assignment');
        $this->assertWorkplace($rel, '2099-02-28', ActualWorkplaceAsOf::RESOLVED, $dest->id, 'assignment');
        $this->assertWorkplace($rel, '2099-03-01', ActualWorkplaceAsOf::RESOLVED, $home->id, 'placement');
    }

    public function test_ca_s27_01_future_relationship_end_keeps_the_relationship_effective_until_then(): void
    {
        [$person, $rel] = $this->personAndRelationship();
        $home = $this->createUnit();
        $this->recordPlacement($rel, $home, '2026-01-15');
        $this->end($person, $rel, '2099-01-01');
        $rel->refresh();

        $current = app(ResolveActualWorkplaceForRelationship::class)($rel);
        $this->assertFalse($current->isResolved(), 'open-period view: a recorded (KNOWN) end resolves nothing');
        $this->assertWorkplace($rel, now()->toDateString(), ActualWorkplaceAsOf::RESOLVED, $home->id, 'placement');
        $this->assertNotNull($this->populationRowOrNull($rel, '2098-12-31'));
        $this->assertNull($this->populationRowOrNull($rel, '2099-01-01'));
        $this->assertWorkplace($rel, '2099-01-01', ActualWorkplaceAsOf::UNRESOLVED, null, null);
    }

    // ---------------------------------------------------------------------
    // D. CA-S27-02 — historical overlap is ambiguous, never a chosen winner
    // ---------------------------------------------------------------------

    public function test_ca_s27_02_overlapping_secondment_and_assignment_are_ambiguous_with_exact_boundaries(): void
    {
        [$rel, $home] = $this->placedRelationship();
        $secDest = $this->createUnit();
        $asgDest = $this->createUnit();
        // LEGACY historical overlap, inserted directly: the pre-S28 write rules compared open
        // records only and accepted exactly this timeline. Since S28 (ADR-S28-001) the commands
        // truncate instead, so it can no longer be produced through them — but already-stored
        // history is never repaired, and the S27 reader must keep reporting it as ambiguous.
        foreach ([['hr.full_secondment_periods', $secDest, '2026-02-01', '2026-06-01'], ['hr.workplace_assignment_periods', $asgDest, '2026-03-01', null]] as [$table, $unit, $from, $to]) {
            DB::table($table)->insert(['id' => (string) Str::uuid7(), 'employment_relationship_id' => $rel->id, 'organizational_unit_id' => $unit->id,
                'effective_from' => $from, 'effective_to' => $to, 'created_at' => now()]);
        }

        $this->assertWorkplace($rel, '2026-01-31', ActualWorkplaceAsOf::RESOLVED, $home->id, 'placement');
        $this->assertWorkplace($rel, '2026-02-28', ActualWorkplaceAsOf::RESOLVED, $secDest->id, 'secondment');

        foreach (['2026-03-01', '2026-04-01', '2026-05-31'] as $date) {
            $resolved = $this->workplace($rel, $date);
            $this->assertSame(ActualWorkplaceAsOf::AMBIGUOUS_MOVEMENT_STATE, $resolved->state(), $date);
            $this->assertNull($resolved->organizationalUnitId(), 'no winner selected and no fallback to placement');
            $this->assertNull($resolved->source());
            $this->assertEqualsCanonicalizing([$secDest->id, $asgDest->id], array_column($resolved->competingMovements(), 'organizational_unit_id'));
            $this->assertEqualsCanonicalizing(['secondment', 'assignment'], array_column($resolved->competingMovements(), 'source'));

            $row = $this->populationRow($rel, $date);
            $this->assertTrue($row->actualWorkplace->isAmbiguous(), 'the ambiguity stays visible in the reporting population');
            $this->assertSame($home->id, $row->placementOrganizationalUnitId, 'placement is still exposed separately, not substituted');
        }

        $this->assertWorkplace($rel, '2026-06-01', ActualWorkplaceAsOf::RESOLVED, $asgDest->id, 'assignment');
    }

    // ---------------------------------------------------------------------
    // E. Professional facts — the population agrees with the existing resolvers
    // ---------------------------------------------------------------------

    public function test_category_contract_job_title_and_specialty_as_of_match_the_existing_resolvers_at_boundaries(): void
    {
        [, $rel] = $this->personAndRelationship('2026-01-01', 'contract');
        app(RecordEmploymentCategoryPeriod::class)->handle($rel, $this->employmentCategory('grade_3'), '2026-01-01');
        app(RecordEmploymentCategoryPeriod::class)->handle($rel, $this->employmentCategory('grade_4'), '2026-04-01');
        app(RecordEmploymentContractPeriod::class)->handle($rel, $this->createSyntheticContractType(), '2026-01-01', '2026-06-01');
        app(RecordEmploymentContractPeriod::class)->handle($rel, $this->createSyntheticContractType(), '2026-06-01', '2027-06-01');
        app(RecordEmploymentJobTitlePeriod::class)->handle($rel, $this->createSyntheticJobTitle(), '2026-01-01');
        app(RecordEmploymentJobTitlePeriod::class)->handle($rel, $this->createSyntheticJobTitle(), '2026-05-01');
        app(RecordEmploymentSpecialtyPeriod::class)->handle($rel, $this->createSyntheticSpecialty(), '2026-02-01');
        app(RecordEmploymentSpecialtyPeriod::class)->handle($rel, $this->createSyntheticSpecialty(), '2026-07-01');

        foreach (['2026-01-01', '2026-01-31', '2026-02-01', '2026-03-31', '2026-04-01', '2026-04-30', '2026-05-01', '2026-05-31', '2026-06-01', '2026-06-30', '2026-07-01', '2027-06-01'] as $date) {
            $row = $this->populationRow($rel, $date);
            $this->assertSame(app(ResolveEmploymentCategoryForRelationshipAsOf::class)($rel, $date)?->id, $row->employmentCategoryId, "category {$date}");
            $this->assertSame(app(ResolveEmploymentContractForRelationshipAsOf::class)($rel, $date)?->contract_type_id, $row->contractTypeId, "contract {$date}");
            $this->assertSame(app(ResolveEmploymentJobTitleForRelationshipAsOf::class)($rel, $date)?->job_title_id, $row->jobTitleId, "job title {$date}");
            $this->assertSame(app(ResolveEmploymentSpecialtyForRelationshipAsOf::class)($rel, $date)?->specialty_id, $row->specialtyId, "specialty {$date}");
        }
        $this->assertNull($this->populationRow($rel, '2026-01-31')->specialtyId, 'before the first specialty → UNRESOLVED');
        $this->assertNull($this->populationRow($rel, '2027-06-01')->contractTypeId, 'after the last contract → UNRESOLVED');
    }

    // ---------------------------------------------------------------------
    // F. S06 mappings as-of
    // ---------------------------------------------------------------------

    public function test_specialty_job_title_and_contract_type_mappings_resolve_on_the_date_with_change_boundaries(): void
    {
        [, $rel] = $this->personAndRelationship('2026-01-01', 'contract');
        $specialty = $this->createSyntheticSpecialty();
        $jobTitle = $this->createSyntheticJobTitle();
        $contractType = $this->createSyntheticContractType();
        app(DefineSpecialtyCadreCategoryMappingPeriod::class)->handle($specialty, $this->cadre('doctors'), '2026-01-01', '2026-07-01');
        app(DefineSpecialtyCadreCategoryMappingPeriod::class)->handle($specialty, $this->cadre('nursing'), '2026-07-01', null);
        app(DefineJobTitleAdministratorClassificationPeriod::class)->handle($jobTitle, false, '2026-01-01', '2026-07-01');
        app(DefineJobTitleAdministratorClassificationPeriod::class)->handle($jobTitle, true, '2026-07-01', null);
        app(DefineContractTypePopulationMappingPeriod::class)->handle($contractType, $this->populationCategory('support_services_daily_worker'), '2026-07-01', null);
        app(RecordEmploymentSpecialtyPeriod::class)->handle($rel, $specialty, '2026-01-01');
        app(RecordEmploymentJobTitlePeriod::class)->handle($rel, $jobTitle, '2026-01-01');
        app(RecordEmploymentContractPeriod::class)->handle($rel, $contractType, '2026-01-01', '2027-01-01');

        $before = $this->populationRow($rel, '2026-06-30');
        $this->assertSame($this->cadre('doctors')->id, $before->cadreCategoryId);
        $this->assertFalse($before->isAdministrator);
        $this->assertNull($before->populationCategoryId, 'no population mapping yet → null, never Other');

        $after = $this->populationRow($rel, '2026-07-01');
        $this->assertSame($this->cadre('nursing')->id, $after->cadreCategoryId);
        $this->assertTrue($after->isAdministrator);
        $this->assertSame($this->populationCategory('support_services_daily_worker')->id, $after->populationCategoryId);
    }

    public function test_a_deactivated_specialty_still_resolves_historically(): void
    {
        [, $rel] = $this->personAndRelationship();
        $specialty = $this->createSyntheticSpecialty();
        app(DefineSpecialtyCadreCategoryMappingPeriod::class)->handle($specialty, $this->cadre('doctors'), '2026-01-01', null);
        app(RecordEmploymentSpecialtyPeriod::class)->handle($rel, $specialty, '2026-01-01');
        app(DeactivateSpecialty::class)->handle($specialty, $specialty->version);

        $row = $this->populationRow($rel, '2026-03-01');
        $this->assertSame($specialty->id, $row->specialtyId);
        $this->assertSame($this->cadre('doctors')->id, $row->cadreCategoryId);
    }

    // ---------------------------------------------------------------------
    // G. Unknown / unresolved policy
    // ---------------------------------------------------------------------

    public function test_unknown_and_unmapped_dimensions_stay_null_and_never_drop_the_row(): void
    {
        $legacyPersonId = (string) Str::uuid7();
        DB::table('hr.persons')->insert(['id' => $legacyPersonId, 'national_id' => $this->uniqueNationalId(), 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $rel = $this->createEmploymentRelationship(Person::query()->findOrFail($legacyPersonId), 'contract', null, '2026-01-01');
        app(RecordEmploymentSpecialtyPeriod::class)->handle($rel, $this->createSyntheticSpecialty(), '2026-01-01');
        app(RecordEmploymentJobTitlePeriod::class)->handle($rel, $this->createSyntheticJobTitle(), '2026-01-01');
        app(RecordEmploymentContractPeriod::class)->handle($rel, $this->createSyntheticContractType(), '2026-01-01', '2027-01-01');
        [, $bare] = $this->personAndRelationship();

        $row = $this->populationRow($rel, '2026-03-01');
        $this->assertNull($row->genderId, 'legacy null gender stays null');
        $this->assertNotNull($row->specialtyId);
        $this->assertNull($row->cadreCategoryId, 'unmapped specialty → null');
        $this->assertNull($row->isAdministrator, 'unmapped job title → null');
        $this->assertNull($row->populationCategoryId, 'unmapped contract type → null');
        $this->assertNull($row->statusDetailId, 'no status recorded → null');
        $this->assertFalse($row->actualWorkplace->isResolved());

        $bareRow = $this->populationRow($bare, '2026-03-01');
        $this->assertNull($bareRow->specialtyId, 'missing specialty stays null');
        $this->assertSame(ActualWorkplaceAsOf::UNRESOLVED, $bareRow->actualWorkplace->state());

        // No generic "Other" catch-all exists (the frozen S06 'other_health_professions' cadre is a
        // real named category, not a fallback) and none was created to absorb unknowns.
        foreach (['ref.specialties', 'ref.monthly_cadre_categories', 'ref.contract_based_population_categories', 'ref.job_titles'] as $table) {
            $this->assertSame(0, DB::table($table)->where(fn ($q) => $q->whereRaw('lower(code) = ?', ['other'])->orWhereRaw('lower(coalesce(name_en, \'\')) = ?', ['other'])->orWhere('name_ar', 'أخرى'))->count(), "no Other value in {$table}");
        }
    }

    public function test_an_unknown_legacy_relationship_is_kept_and_flagged_not_reinterpreted(): void
    {
        $person = $this->createPersonRecord();
        $relId = (string) Str::uuid7();
        DB::table('hr.employment_relationships')->insert([
            'id' => $relId, 'person_id' => $person->id, 'employment_type_id' => $this->employmentType('contract')->id,
            'employee_number' => 'CN-LEGACY-'.Str::upper(Str::random(8)), 'employee_number_scheme' => 'CONTRACT', 'effective_from' => '2020-01-01',
            'effective_to' => null, 'end_knowledge_state' => 'UNKNOWN_LEGACY', 'version' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $row = $this->populationRow(EmploymentRelationship::query()->findOrFail($relId), '2026-03-01');
        $this->assertSame('UNKNOWN_LEGACY', $row->relationshipEndKnowledgeState);
        $this->assertSame('END_UNKNOWN_LEGACY', $row->relationshipAsOfState);
    }

    // ---------------------------------------------------------------------
    // H. Reappointment isolation
    // ---------------------------------------------------------------------

    public function test_reappointment_relationships_stay_separate_and_nothing_leaks(): void
    {
        [$person, $old] = $this->personAndRelationship('2026-01-01', 'contract');
        $oldSpecialty = $this->createSyntheticSpecialty();
        app(RecordEmploymentSpecialtyPeriod::class)->handle($old, $oldSpecialty, '2026-01-01');
        app(RecordEmploymentJobTitlePeriod::class)->handle($old, $this->createSyntheticJobTitle(), '2026-01-01');
        app(RecordEmploymentCategoryPeriod::class)->handle($old, $this->employmentCategory('grade_3'), '2026-01-01');
        app(RecordEmploymentContractPeriod::class)->handle($old, $this->createSyntheticContractType(), '2026-01-01', '2026-06-01');
        $this->end($person, $old, '2026-06-01');
        $new = $this->createEmploymentRelationship($person, 'contract', null, '2026-08-01');

        $atOld = $this->population('2026-03-01', [$old->id, $new->id]);
        $this->assertSame([$old->id], array_map(fn ($r) => $r->employmentRelationshipId, $atOld));
        $this->assertSame($oldSpecialty->id, $atOld[0]->specialtyId);

        $this->assertSame([], $this->population('2026-07-01', [$old->id, $new->id]), 'between episodes nothing is effective');

        $atNew = $this->population('2026-09-01', [$old->id, $new->id]);
        $this->assertSame([$new->id], array_map(fn ($r) => $r->employmentRelationshipId, $atNew), 'one row, never merged with the old episode');
        $this->assertNull($atNew[0]->specialtyId);
        $this->assertNull($atNew[0]->jobTitleId);
        $this->assertNull($atNew[0]->employmentCategoryId);
        $this->assertNull($atNew[0]->contractTypeId);
    }

    // ---------------------------------------------------------------------
    // I. R1–R5 readiness (facts only — no report is implemented)
    // ---------------------------------------------------------------------

    public function test_r1_readiness_status_specialty_cadre_placement_and_actual_workplace(): void
    {
        [$person, $rel] = $this->personAndRelationship();
        $home = $this->createUnit();
        $this->recordPlacement($rel, $home, '2026-01-15');
        $this->recordStatus($person, $rel, 'on_duty', '2026-09-27');
        $specialty = $this->createSyntheticSpecialty();
        app(DefineSpecialtyCadreCategoryMappingPeriod::class)->handle($specialty, $this->cadre('doctors'), '2026-01-01', null);
        app(RecordEmploymentSpecialtyPeriod::class)->handle($rel, $specialty, '2026-01-01');

        $row = $this->populationRow($rel, '2026-10-15');
        $this->assertSame('on_duty', $row->statusDetailCode);
        $behavior = app(ResolveEmploymentStatusDetailBehaviorAsOf::class)($this->statusDetail('on_duty'), '2026-10-15');
        $this->assertSame($behavior->counts_in_monthly_reporting === null ? null : (bool) $behavior->counts_in_monthly_reporting, $row->countsInMonthlyReporting);
        $this->assertSame((bool) $behavior->participates_in_active_workforce, $row->participatesInActiveWorkforce);
        $this->assertSame($specialty->id, $row->specialtyId);
        $this->assertSame($this->cadre('doctors')->id, $row->cadreCategoryId);
        $this->assertSame($home->id, $row->placementOrganizationalUnitId);
        $this->assertSame($home->id, $row->actualWorkplace->organizationalUnitId());
    }

    public function test_r2_readiness_job_title_administrator_gender_status_and_actual_workplace(): void
    {
        [$person, $rel] = $this->personAndRelationship();
        $home = $this->createUnit();
        $this->recordPlacement($rel, $home, '2026-01-15');
        $dest = $this->createUnit();
        app(StartWorkplaceAssignment::class)->handle($rel, $dest, '2026-02-01', $this->assignmentDecisionType());
        $this->recordStatus($person, $rel, 'on_duty', '2026-09-27');
        $jobTitle = $this->createSyntheticJobTitle();
        app(DefineJobTitleAdministratorClassificationPeriod::class)->handle($jobTitle, true, '2026-01-01', null);
        app(RecordEmploymentJobTitlePeriod::class)->handle($rel, $jobTitle, '2026-01-01');

        $row = $this->populationRow($rel, '2026-10-15');
        $this->assertSame($jobTitle->id, $row->jobTitleId);
        $this->assertTrue($row->isAdministrator);
        $this->assertSame($person->gender_id, $row->genderId);
        $this->assertSame('on_duty', $row->statusDetailCode);
        $this->assertSame($dest->id, $row->actualWorkplace->organizationalUnitId(), 'actual work = the assignment destination');
        $this->assertSame($home->id, $row->placementOrganizationalUnitId);
    }

    public function test_r3_readiness_not_on_duty_status_detail_behavior_and_workplaces(): void
    {
        [$person, $rel] = $this->personAndRelationship();
        $home = $this->createUnit();
        $this->recordPlacement($rel, $home, '2026-01-15');
        $this->recordStatus($person, $rel, 'on_duty', '2026-09-27');
        $this->recordStatus($person, $rel, 'external_sick_leave', '2026-10-01');

        $row = $this->populationRow($rel, '2026-10-15');
        $this->assertSame('external_sick_leave', $row->statusDetailCode, 'the status detail itself is the reason — no new reason mapping');
        $this->assertNotNull($row->participatesInActiveWorkforce);
        $this->assertSame($home->id, $row->placementOrganizationalUnitId);
        $this->assertSame($home->id, $row->actualWorkplace->organizationalUnitId());
    }

    public function test_r4_readiness_contract_type_population_status_and_workplace(): void
    {
        [$person, $rel] = $this->personAndRelationship('2026-01-01', 'contract');
        $home = $this->createUnit();
        $this->recordPlacement($rel, $home, '2026-01-15');
        $this->recordStatus($person, $rel, 'on_duty', '2026-09-27');
        $contractType = $this->createSyntheticContractType();
        app(DefineContractTypePopulationMappingPeriod::class)->handle($contractType, $this->populationCategory('support_services_daily_worker'), '2026-01-01', null);
        $contract = app(RecordEmploymentContractPeriod::class)->handle($rel, $contractType, '2026-01-01', '2027-01-01');

        $row = $this->populationRow($rel, '2026-10-15');
        $this->assertSame($contract->id, $row->contractPeriodId);
        $this->assertSame($contractType->id, $row->contractTypeId);
        $this->assertSame($this->populationCategory('support_services_daily_worker')->id, $row->populationCategoryId);
        $this->assertSame('on_duty', $row->statusDetailCode);
        $this->assertSame($home->id, $row->actualWorkplace->organizationalUnitId());
    }

    public function test_r5_readiness_population_classification_specialty_status_and_workplace(): void
    {
        [$person, $rel] = $this->personAndRelationship('2026-01-01', 'contract');
        $home = $this->createUnit();
        $this->recordPlacement($rel, $home, '2026-01-15');
        $this->recordStatus($person, $rel, 'on_duty', '2026-09-27');
        $contractType = $this->createSyntheticContractType();
        app(DefineContractTypePopulationMappingPeriod::class)->handle($contractType, $this->populationCategory('volunteer_unemployment'), '2026-01-01', null);
        app(RecordEmploymentContractPeriod::class)->handle($rel, $contractType, '2026-01-01', '2027-01-01');
        $specialty = $this->createSyntheticSpecialty();
        app(RecordEmploymentSpecialtyPeriod::class)->handle($rel, $specialty, '2026-01-01');

        $row = $this->populationRow($rel, '2026-10-15');
        $this->assertSame($this->populationCategory('volunteer_unemployment')->id, $row->populationCategoryId);
        $this->assertSame($specialty->id, $row->specialtyId);
        $this->assertSame('on_duty', $row->statusDetailCode);
        $this->assertSame($home->id, $row->placementOrganizationalUnitId);
    }

    // ---------------------------------------------------------------------
    // J. Query strategy, input, and scope guards
    // ---------------------------------------------------------------------

    public function test_the_population_uses_one_query_regardless_of_size_and_one_row_per_relationship(): void
    {
        $ids = [];
        foreach (range(1, 6) as $i) {
            [$person, $rel] = $this->personAndRelationship();
            $this->recordPlacement($rel, $this->createUnit(), '2026-01-15');
            $this->recordStatus($person, $rel, 'on_duty', '2026-09-27');
            app(RecordEmploymentSpecialtyPeriod::class)->handle($rel, $this->createSyntheticSpecialty(), '2026-01-01');
            $ids[] = $rel->id;
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        $rows = $this->population('2026-10-15', $ids);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame(1, $queries, 'no N+1: one live SQL statement for the whole population');
        $this->assertCount(6, $rows);
        $this->assertSame(count($ids), count(array_unique(array_map(fn ($r) => $r->employmentRelationshipId, $rows))));
    }

    public function test_an_explicit_valid_business_date_is_required(): void
    {
        foreach (['', 'today', '2026-02-30', '2026-3-1'] as $bad) {
            try {
                app(ListReportingPopulationAsOf::class)($bad);
                $this->fail("{$bad} must be rejected");
            } catch (InvalidArgumentException) {
            }
        }
        $this->assertIsArray(app(ListReportingPopulationAsOf::class)('2026-03-01', []));
    }

    public function test_s27_adds_no_schema_materialization_partial_secondment_route_or_output(): void
    {
        $this->assertSame(0, (int) DB::selectOne('select count(*) as c from pg_matviews')->c, 'no materialized view');
        $this->assertSame(0, DB::table('information_schema.tables')->where('table_schema', 'reporting')->count(), 'no reporting tables / snapshots');
        // 'weekday'/'work_schedule'/'schedule' were removed in S29 — Work Schedule Foundation
        // (docs/work-schedule-foundation-specification.md) is now an authorized domain itself, the
        // same precedent as earlier stages; 'partial' / 'PartialSecondment' likewise in S30
        // (docs/partial-secondment-foundation-specification.md). S27 still adds none of them.
        foreach (['allocation', 'snapshot', 'report'] as $forbidden) {
            $this->assertSame(0, DB::table('information_schema.tables')->whereIn('table_schema', ['hr', 'ref', 'reporting', 'org'])->where('table_name', 'like', "%{$forbidden}%")->count(), "no {$forbidden} table");
        }
        $s29Migrations = [
            '2026_10_12_000001_create_ref_weekdays_table.php',
            '2026_10_12_000002_create_hr_work_schedule_periods_table.php',
            '2026_10_12_000003_seed_security_work_schedule_period_permissions.php',
        ];
        $this->assertSame([], array_values(array_diff(array_map('basename', glob(base_path('database/migrations/2026_10_12_*.php'))), $s29Migrations)), 'S27 adds no migration (the 2026_10_12 files are S29\'s)');

        foreach (Route::getRoutes() as $route) {
            $this->assertDoesNotMatchRegularExpression('/report|export|dashboard|as-of|pdf|xlsx|csv/i', $route->uri(), 'S27 exposes no endpoint or output');
        }
        foreach (['Pdf', 'Xlsx', 'Csv', 'Export', 'Dashboard', 'Chart'] as $forbidden) {
            $this->assertSame([], glob(base_path("app/Modules/*/*/*{$forbidden}*.php")), "no {$forbidden} class");
            $this->assertSame([], glob(base_path("app/Modules/*/*/*/*{$forbidden}*.php")), "no {$forbidden} class");
            $this->assertSame([], glob(base_path("app/Modules/*/*/*/*/*{$forbidden}*.php")), "no {$forbidden} class");
        }
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    /** @return array{0: Person, 1: EmploymentRelationship} */
    private function personAndRelationship(string $from = '2026-01-01', string $type = 'permanent'): array
    {
        $person = $this->createPersonRecord();

        return [$person, $this->createEmploymentRelationship($person, $type, null, $from)];
    }

    /** @return array{0: EmploymentRelationship, 1: OrganizationalUnit} */
    private function placedRelationship(): array
    {
        [, $rel] = $this->personAndRelationship();
        $home = $this->createUnit();
        $this->recordPlacement($rel, $home, '2026-01-15');

        return [$rel->refresh(), $home];
    }

    private function recordStatus(Person $person, EmploymentRelationship $rel, string $code, string $from): void
    {
        app(RecordEmploymentStatusPeriod::class)->handle($person, $rel->refresh(), $this->statusDetail($code), $from);
    }

    private function end(Person $person, EmploymentRelationship $rel, string $to): void
    {
        $rel->refresh();
        app(EndEmploymentRelationship::class)->handle($person, $rel, $rel->version, $to, false);
    }

    private function statusAsOf(EmploymentRelationship $rel, string $date)
    {
        return app(ResolveEmploymentStatusForRelationshipAsOf::class)($rel, $date);
    }

    private function statusCodeAsOf(EmploymentRelationship $rel, string $date): ?string
    {
        $period = $this->statusAsOf($rel, $date);

        return $period === null ? null : EmploymentStatusDetail::query()->findOrFail($period->status_detail_id)->code;
    }

    private function placementUnit(EmploymentRelationship $rel, string $date): ?string
    {
        return app(ResolveOrganizationalPlacementForRelationshipAsOf::class)($rel, $date)?->organizational_unit_id;
    }

    private function workplace(EmploymentRelationship $rel, string $date): ActualWorkplaceAsOf
    {
        return app(ResolveActualWorkplaceForRelationshipAsOf::class)($rel->refresh(), $date);
    }

    /** Asserts the single resolver AND the population row (when the relationship is in it) agree. */
    private function assertWorkplace(EmploymentRelationship $rel, string $date, string $state, ?string $unitId, ?string $source): void
    {
        $single = $this->workplace($rel, $date);
        $this->assertSame($state, $single->state(), "state on {$date}");
        if ($unitId !== null) {
            $this->assertSame($unitId, $single->organizationalUnitId(), "unit on {$date}");
        }
        $this->assertSame($source, $single->source(), "source on {$date}");

        $row = $this->populationRowOrNull($rel, $date);
        if ($row !== null) {
            $this->assertSame($single->state(), $row->actualWorkplace->state(), "population state on {$date}");
            $this->assertSame($single->organizationalUnitId(), $row->actualWorkplace->organizationalUnitId(), "population unit on {$date}");
            $this->assertSame($single->source(), $row->actualWorkplace->source(), "population source on {$date}");
        }
    }

    /** @return list<ReportingPopulationRow> */
    private function population(string $date, array $ids): array
    {
        return app(ListReportingPopulationAsOf::class)($date, $ids);
    }

    private function populationRowOrNull(EmploymentRelationship $rel, string $date): ?ReportingPopulationRow
    {
        $rows = $this->population($date, [$rel->id]);
        $this->assertLessThanOrEqual(1, count($rows));

        return $rows[0] ?? null;
    }

    private function populationRow(EmploymentRelationship $rel, string $date): ReportingPopulationRow
    {
        $row = $this->populationRowOrNull($rel, $date);
        $this->assertNotNull($row, "relationship expected in the population on {$date}");

        return $row;
    }

    private function cadre(string $code): MonthlyCadreCategory
    {
        return MonthlyCadreCategory::query()->where('code', $code)->firstOrFail();
    }

    private function populationCategory(string $code): ContractBasedPopulationCategory
    {
        return ContractBasedPopulationCategory::query()->where('code', $code)->firstOrFail();
    }
}
