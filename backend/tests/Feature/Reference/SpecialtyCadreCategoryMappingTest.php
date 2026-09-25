<?php

namespace Tests\Feature\Reference;

use App\Modules\Reference\Application\Queries\ResolveSpecialtyCadreCategoryAsOf;
use App\Modules\Reference\Infrastructure\Persistence\Eloquent\MonthlyCadreCategory;
use App\Modules\Reference\Infrastructure\Persistence\Eloquent\Specialty;
use Illuminate\Support\Str;

/**
 * S06 spec §12.2: temporal specialty → cadre-category mapping periods — append-only,
 * non-overlapping (exclusive per specialty), current-on-date resolution. Mirrors
 * EmploymentStatusDetailBehaviorTest's structure exactly.
 */
class SpecialtyCadreCategoryMappingTest extends ReferenceTestCase
{
    private function specialty(): Specialty
    {
        return Specialty::create([
            'code' => 'spec_'.Str::lower(Str::random(10)),
            'name_ar' => 'تخصص',
        ]);
    }

    private function cadreCategory(): MonthlyCadreCategory
    {
        return MonthlyCadreCategory::create([
            'code' => 'cadre_'.Str::lower(Str::random(10)),
            'name_ar' => 'فئة',
        ]);
    }

    public function test_defining_an_open_ended_period_succeeds_and_is_audited(): void
    {
        $this->actingAsReferenceManager();
        $specialty = $this->specialty();
        $category = $this->cadreCategory();

        $response = $this->postJson(
            "/api/v1/reference/specialties/{$specialty->id}/cadre-category-mappings",
            ['cadre_category_id' => $category->id, 'effective_from' => '2026-01-01'],
        )->assertCreated();

        $response->assertJsonPath('effective_from', '2026-01-01')->assertJsonPath('effective_to', null);

        $entry = $this->latestAuditEntryFor('reference.specialty_cadre_category_mapping.period.define');
        $this->assertSame($specialty->id, $entry->changes['specialty_id']);
        $this->assertSame($category->id, $entry->changes['cadre_category_id']);
    }

    public function test_create_is_rejected_without_manage_permission(): void
    {
        $this->actingAsReferenceViewer();
        $specialty = $this->specialty();
        $category = $this->cadreCategory();

        $this->postJson(
            "/api/v1/reference/specialties/{$specialty->id}/cadre-category-mappings",
            ['cadre_category_id' => $category->id, 'effective_from' => '2026-01-01'],
        )->assertStatus(403);
    }

    public function test_a_second_period_that_closes_the_first_with_no_gap_succeeds(): void
    {
        $this->actingAsReferenceManager();
        $specialty = $this->specialty();
        $categoryA = $this->cadreCategory();
        $categoryB = $this->cadreCategory();

        $this->postJson("/api/v1/reference/specialties/{$specialty->id}/cadre-category-mappings", [
            'cadre_category_id' => $categoryA->id, 'effective_from' => '2026-01-01', 'effective_to' => '2026-06-01',
        ])->assertCreated();

        $this->postJson("/api/v1/reference/specialties/{$specialty->id}/cadre-category-mappings", [
            'cadre_category_id' => $categoryB->id, 'effective_from' => '2026-06-01',
        ])->assertCreated();

        $this->getJson("/api/v1/reference/specialties/{$specialty->id}/cadre-category-mappings")
            ->assertOk()
            ->assertJsonCount(2);
    }

    public function test_an_overlapping_period_is_rejected_with_409(): void
    {
        $this->actingAsReferenceManager();
        $specialty = $this->specialty();
        $categoryA = $this->cadreCategory();
        $categoryB = $this->cadreCategory();

        $this->postJson("/api/v1/reference/specialties/{$specialty->id}/cadre-category-mappings", [
            'cadre_category_id' => $categoryA->id, 'effective_from' => '2026-01-01', 'effective_to' => '2026-12-01',
        ])->assertCreated();

        // Starts before the first period's effective_to — genuinely overlaps, even to a different category.
        $this->postJson("/api/v1/reference/specialties/{$specialty->id}/cadre-category-mappings", [
            'cadre_category_id' => $categoryB->id, 'effective_from' => '2026-06-01',
        ])->assertStatus(409);
    }

    public function test_a_specialty_with_no_covering_period_is_unresolved(): void
    {
        $this->actingAsReferenceManager();
        $specialty = $this->specialty();

        $this->getJson("/api/v1/reference/specialties/{$specialty->id}/cadre-category-mappings")
            ->assertOk()
            ->assertJsonCount(0);

        $resolved = app(ResolveSpecialtyCadreCategoryAsOf::class)->__invoke($specialty, '2026-06-01');
        $this->assertNull($resolved, 'a specialty with no covering period must resolve to UNRESOLVED (null), never a guess');
    }

    public function test_as_of_resolver_resolves_correctly_across_a_period_boundary(): void
    {
        $this->actingAsReferenceManager();
        $specialty = $this->specialty();
        $categoryA = $this->cadreCategory();
        $categoryB = $this->cadreCategory();

        $this->postJson("/api/v1/reference/specialties/{$specialty->id}/cadre-category-mappings", [
            'cadre_category_id' => $categoryA->id, 'effective_from' => '2026-01-01', 'effective_to' => '2026-06-01',
        ])->assertCreated();

        $this->postJson("/api/v1/reference/specialties/{$specialty->id}/cadre-category-mappings", [
            'cadre_category_id' => $categoryB->id, 'effective_from' => '2026-06-01',
        ])->assertCreated();

        $resolver = app(ResolveSpecialtyCadreCategoryAsOf::class);

        $before = $resolver->__invoke($specialty, '2026-03-01');
        $this->assertSame($categoryA->id, $before?->id);

        $after = $resolver->__invoke($specialty, '2026-09-01');
        $this->assertSame($categoryB->id, $after?->id);

        $unresolvedBefore = $resolver->__invoke($specialty, '2025-12-31');
        $this->assertNull($unresolvedBefore, 'a date before any period was defined must be UNRESOLVED');
    }

    public function test_resolution_still_works_for_a_period_pointing_to_a_now_deactivated_category(): void
    {
        $this->actingAsReferenceManager();
        $specialty = $this->specialty();
        $category = $this->cadreCategory();

        $this->postJson("/api/v1/reference/specialties/{$specialty->id}/cadre-category-mappings", [
            'cadre_category_id' => $category->id, 'effective_from' => '2026-01-01',
        ])->assertCreated();

        $this->postJson("/api/v1/reference/monthly-cadre-categories/{$category->id}/deactivate", [
            'expected_version' => 1,
        ])->assertOk();

        // RESOLUTION (S06 spec §10, mirrors CORRECTIVE-01 §8): deactivating the target category
        // never destroys resolution for a period already defined against it.
        $resolved = app(ResolveSpecialtyCadreCategoryAsOf::class)->__invoke($specialty, '2026-06-01');
        $this->assertNotNull($resolved);
        $this->assertSame($category->id, $resolved->id);
        $this->assertFalse((bool) $resolved->is_active);
    }
}
