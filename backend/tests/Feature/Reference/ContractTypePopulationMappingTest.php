<?php

namespace Tests\Feature\Reference;

use App\Modules\Reference\Application\Queries\ResolveContractTypePopulationCategoryAsOf;
use App\Modules\Reference\Infrastructure\Persistence\Eloquent\ContractBasedPopulationCategory;
use App\Modules\Reference\Infrastructure\Persistence\Eloquent\ContractType;
use Illuminate\Support\Str;

/**
 * S06 spec §12.5: temporal contract-type → population-category mapping periods — append-only,
 * non-overlapping (exclusive per contract type), current-on-date resolution.
 */
class ContractTypePopulationMappingTest extends ReferenceTestCase
{
    private function contractType(): ContractType
    {
        return ContractType::create([
            'code' => 'ct_'.Str::lower(Str::random(10)),
            'name_ar' => 'نوع عقد',
        ]);
    }

    private function populationCategory(): ContractBasedPopulationCategory
    {
        return ContractBasedPopulationCategory::create([
            'code' => 'pop_'.Str::lower(Str::random(10)),
            'name_ar' => 'فئة',
        ]);
    }

    public function test_defining_an_open_ended_period_succeeds_and_is_audited(): void
    {
        $this->actingAsReferenceManager();
        $contractType = $this->contractType();
        $category = $this->populationCategory();

        $response = $this->postJson(
            "/api/v1/reference/contract-types/{$contractType->id}/population-mappings",
            ['population_category_id' => $category->id, 'effective_from' => '2026-01-01'],
        )->assertCreated();

        $response->assertJsonPath('population_category_id', $category->id)->assertJsonPath('effective_to', null);

        $entry = $this->latestAuditEntryFor('reference.contract_type_population_mapping.period.define');
        $this->assertSame($contractType->id, $entry->changes['contract_type_id']);
    }

    public function test_create_is_rejected_without_manage_permission(): void
    {
        $this->actingAsReferenceViewer();
        $contractType = $this->contractType();
        $category = $this->populationCategory();

        $this->postJson(
            "/api/v1/reference/contract-types/{$contractType->id}/population-mappings",
            ['population_category_id' => $category->id, 'effective_from' => '2026-01-01'],
        )->assertStatus(403);
    }

    public function test_an_overlapping_period_is_rejected_with_409(): void
    {
        $this->actingAsReferenceManager();
        $contractType = $this->contractType();
        $categoryA = $this->populationCategory();
        $categoryB = $this->populationCategory();

        $this->postJson("/api/v1/reference/contract-types/{$contractType->id}/population-mappings", [
            'population_category_id' => $categoryA->id, 'effective_from' => '2026-01-01', 'effective_to' => '2026-12-01',
        ])->assertCreated();

        $this->postJson("/api/v1/reference/contract-types/{$contractType->id}/population-mappings", [
            'population_category_id' => $categoryB->id, 'effective_from' => '2026-06-01',
        ])->assertStatus(409);
    }

    public function test_as_of_resolver_resolves_correctly_across_a_period_boundary_and_is_unresolved_before(): void
    {
        $this->actingAsReferenceManager();
        $contractType = $this->contractType();
        $categoryA = $this->populationCategory();
        $categoryB = $this->populationCategory();

        $this->postJson("/api/v1/reference/contract-types/{$contractType->id}/population-mappings", [
            'population_category_id' => $categoryA->id, 'effective_from' => '2026-01-01', 'effective_to' => '2026-06-01',
        ])->assertCreated();

        $this->postJson("/api/v1/reference/contract-types/{$contractType->id}/population-mappings", [
            'population_category_id' => $categoryB->id, 'effective_from' => '2026-06-01',
        ])->assertCreated();

        $resolver = app(ResolveContractTypePopulationCategoryAsOf::class);

        $this->assertSame($categoryA->id, $resolver->__invoke($contractType, '2026-03-01')?->id);
        $this->assertSame($categoryB->id, $resolver->__invoke($contractType, '2026-09-01')?->id);
        $this->assertNull($resolver->__invoke($contractType, '2025-12-31'));
    }
}
