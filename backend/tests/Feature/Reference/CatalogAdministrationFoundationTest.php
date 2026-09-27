<?php

namespace Tests\Feature\Reference;

use App\Modules\Reference\Infrastructure\Persistence\Eloquent\AcademicDegree;
use App\Modules\Reference\Infrastructure\Persistence\Eloquent\ContractType;
use App\Modules\Reference\Infrastructure\Persistence\Eloquent\DecisionType;
use App\Modules\Reference\Infrastructure\Persistence\Eloquent\EmploymentCategory;
use App\Modules\Reference\Infrastructure\Persistence\Eloquent\Gender;
use App\Modules\Reference\Infrastructure\Persistence\Eloquent\JobTitle;
use App\Modules\Reference\Infrastructure\Persistence\Eloquent\LeaveStatus;
use App\Modules\Reference\Infrastructure\Persistence\Eloquent\LeaveType;
use App\Modules\Reference\Infrastructure\Persistence\Eloquent\QualificationType;
use App\Modules\Reference\Infrastructure\Persistence\Eloquent\SupervisoryTitle;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * S13 cross-cutting coverage (docs/reference-catalog-administration-foundation-specification.md
 * §6/§7/§8/§9/§24) that does not belong to any single catalog's own lifecycle test: the registry's
 * allowlist boundary, the `ref.employment_categories` business-content acceptance, the confirmation
 * that `ref.decision_types` needed no new S13 work, cross-catalog ID misuse, and the
 * supervisory-statuses deferral decision.
 */
class CatalogAdministrationFoundationTest extends ReferenceTestCase
{
    public function test_unknown_catalog_segment_returns_404(): void
    {
        $this->actingAsReferenceManager();

        $this->getJson('/api/v1/reference/not-a-real-catalog')->assertStatus(404);
        $this->postJson('/api/v1/reference/not-a-real-catalog', ['code' => 'x', 'name_ar' => 'x'])
            ->assertStatus(404);
    }

    public function test_employment_categories_seed_contains_exactly_the_seven_authorized_grades(): void
    {
        $rows = EmploymentCategory::query()->orderBy('display_order')->get();

        $this->assertCount(7, $rows);

        $expected = [
            'grade_1' => 'الأولى',
            'grade_2' => 'الثانية',
            'grade_3' => 'الثالثة',
            'grade_4' => 'الرابعة',
            'grade_5' => 'الخامسة',
            'grade_senior' => 'العليا',
            'grade_old_law' => 'قانون قديم',
        ];

        $this->assertSame(array_keys($expected), $rows->pluck('code')->all());

        foreach ($rows as $row) {
            $this->assertSame($expected[$row->code], $row->name_ar);
            // S05 §8 precedent: no English gloss was supplied for any of the seven values, so
            // name_en must be null, never invented (spec §8.1).
            $this->assertNull($row->name_en);
            $this->assertTrue((bool) $row->is_active);
            $this->assertSame(1, $row->version);
        }
    }

    public function test_no_other_catalog_received_new_business_content_from_s13(): void
    {
        // Only ref.employment_categories is seeded by S13 (spec §8). Every other newly-administered
        // catalog must remain empty — confirms capability-without-content for the other seven.
        $this->assertSame(0, JobTitle::query()->count());
        $this->assertSame(0, ContractType::query()->count());
        $this->assertSame(0, QualificationType::query()->count());
        $this->assertSame(0, AcademicDegree::query()->count());
        $this->assertSame(0, SupervisoryTitle::query()->count());
        $this->assertSame(0, LeaveType::query()->count());
        $this->assertSame(0, LeaveStatus::query()->count());
        // ref.decision_types: already existed from S05 with zero rows; S13 supplied no approved
        // Arabic value for it either, so it stayed empty as of S13 (spec §4.2, §7, §8). This
        // assertion is scoped to "no S13 content" specifically (excluding the codes S14/S16 later
        // seeded) rather than to a total count, because S14's own
        // 2026_10_04_000001_seed_ref_decision_types_transfer migration (ADR-S14-002) legitimately
        // adds exactly one row — TRANSFER/نقل — and S16's own
        // 2026_10_05_000002_seed_ref_decision_types_assignment migration (ADR-S16-001 §14)
        // legitimately adds exactly one more — ASSIGNMENT/تكليف — afterward; neither addition is
        // S13's, and each is covered by its own tests
        // (tests/Feature/HumanResources/TransferFoundationTest.php,
        // tests/Feature/HumanResources/WorkplaceAssignmentFoundationTest.php).
        $this->assertSame(0, DecisionType::query()->whereNotIn('code', ['TRANSFER', 'ASSIGNMENT'])->count());
    }

    public function test_decision_type_administration_already_worked_before_s13_and_is_unaffected_by_it(): void
    {
        // Documents spec §4.2/§7: ref.decision_types already had full Create/Activate/Deactivate/
        // UpdateMetadata administration since S05 — S13 added no new file for it. This is a
        // regression guard, not new capability.
        $this->actingAsReferenceManager();

        $response = $this->postJson('/api/v1/reference/decision-types', [
            'code' => 'dt_'.Str::lower(Str::random(10)),
            'name_ar' => 'قيمة',
        ])->assertCreated();

        $this->assertNotNull($this->latestAuditEntryFor('reference.decision_type.create'));
        $response->assertJsonPath('version', 1);
    }

    public function test_cross_catalog_id_is_not_resolved_by_a_different_catalogs_route(): void
    {
        $this->actingAsReferenceManager();

        $gender = Gender::query()->first();
        $this->assertNotNull($gender, 'S05 baseline seed should have left at least one gender row.');

        // A structurally-valid UUID that genuinely exists — just in the wrong table — must not
        // resolve via a different catalog's route-model binding.
        $this->getJson("/api/v1/reference/leave-types/{$gender->id}")->assertStatus(404);
        $this->patchJson("/api/v1/reference/leave-types/{$gender->id}", [
            'name_ar' => 'x',
            'expected_version' => 1,
        ])->assertStatus(404);
    }

    public function test_supervisory_statuses_table_was_deliberately_not_created(): void
    {
        // Regression guard for the spec §9 decision: insufficient S05/S06 evidence to build a
        // dedicated ref.supervisory_statuses catalog, so S13 explicitly did not create one. If a
        // future stage adds it, that stage should update/remove this guard deliberately rather than
        // this table appearing as an unplanned side effect.
        $this->assertFalse(Schema::hasTable('ref.supervisory_statuses'));
    }

    public function test_employment_types_and_specialties_remain_unadministered_and_out_of_s13_scope(): void
    {
        // spec §7: not named by the S13 authorization, deliberately left untouched.
        $this->getJson('/api/v1/reference/employment-types')->assertStatus(404);
        $this->getJson('/api/v1/reference/specialties')->assertStatus(404);
    }
}
