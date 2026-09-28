<?php

namespace Tests\Feature\Reference;

use App\Modules\HumanResources\Infrastructure\Authorization\HumanResourcesPermissionCatalog;
use App\Modules\Reference\Application\Commands\CreateSpecialty;
use App\Modules\Reference\Application\Queries\ResolveSpecialtyCadreCategoryAsOf;
use App\Modules\Reference\Domain\Exceptions\DuplicateReferenceCodeException;
use App\Modules\Reference\Infrastructure\Persistence\Eloquent\MonthlyCadreCategory;
use App\Modules\Reference\Infrastructure\Persistence\Eloquent\Specialty;
use App\Modules\Reference\Infrastructure\Persistence\Eloquent\SpecialtyCadreCategoryMapping;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/**
 * S25 Specialty Catalog Administration Foundation
 * (docs/specialty-catalog-administration-foundation-specification.md, ADR-S25-001): the existing
 * canonical ref.specialties catalog gains exactly the S13 simple-reference administration
 * (list/show/create/update metadata/activate/deactivate, no hard delete) under the shared
 * reference.view / reference.manage permissions, audited through S04. No schema change, no seed,
 * no "Other", no employee assignment, no automatic S06 cadre mapping. All values synthetic.
 */
class SpecialtyCatalogAdministrationFoundationTest extends ReferenceTestCase
{
    private const URL = '/api/v1/reference/specialties';

    // ---------------------------------------------------------------------
    // Catalog
    // ---------------------------------------------------------------------

    public function test_the_catalog_is_unseeded_and_lists_administered_rows(): void
    {
        $this->assertSame(0, Specialty::query()->count(), 'ADR-S25-001 D: no specialty value is seeded');

        $this->actingAsReferenceManager();
        $this->getJson(self::URL)->assertOk()->assertJsonCount(0, 'data');

        $b = $this->create(['display_order' => 2])->json();
        $a = $this->create(['display_order' => 1])->json();

        $this->getJson(self::URL)->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $a['id'])
            ->assertJsonPath('data.1.id', $b['id']);
    }

    public function test_create_returns_the_standard_reference_shape(): void
    {
        $this->actingAsReferenceManager();
        $code = $this->code();

        $response = $this->postJson(self::URL, ['code' => $code, 'name_ar' => 'تخصص تجريبي', 'name_en' => 'Synthetic Specialty', 'display_order' => 3])
            ->assertCreated()
            ->assertJsonPath('code', $code)
            ->assertJsonPath('name_ar', 'تخصص تجريبي')
            ->assertJsonPath('name_en', 'Synthetic Specialty')
            ->assertJsonPath('display_order', 3)
            ->assertJsonPath('is_active', true)
            ->assertJsonPath('version', 1);

        $this->assertSame(
            ['id', 'code', 'name_ar', 'name_en', 'is_active', 'display_order', 'version', 'created_at', 'updated_at'],
            array_keys($response->json()),
            'the S13 SimpleReferenceValueResource shape — no category, cadre, academic/professional or employee field',
        );
        $this->getJson(self::URL.'/'.$response->json('id'))->assertOk()->assertJsonPath('code', $code);
    }

    public function test_name_en_and_display_order_are_optional(): void
    {
        $this->actingAsReferenceManager();

        $this->postJson(self::URL, ['code' => $this->code(), 'name_ar' => 'تخصص'])
            ->assertCreated()->assertJsonPath('name_en', null)->assertJsonPath('display_order', 0);
    }

    public function test_duplicate_code_is_rejected_without_a_second_row_or_audit(): void
    {
        $this->actingAsReferenceManager();
        $code = $this->code();
        $this->postJson(self::URL, ['code' => $code, 'name_ar' => 'أ'])->assertCreated();
        $auditBefore = $this->auditEntriesCount();

        $this->postJson(self::URL, ['code' => $code, 'name_ar' => 'ب'])->assertStatus(422);

        $this->assertSame(1, Specialty::query()->where('code', $code)->count());
        $this->assertSame($auditBefore, $this->auditEntriesCount());

        $this->expectException(DuplicateReferenceCodeException::class);
        app(CreateSpecialty::class)->handle($code, 'ج', null, null);
    }

    public function test_invalid_or_blank_fields_are_rejected_by_the_established_reference_rules(): void
    {
        $this->actingAsReferenceManager();
        $auditBefore = $this->auditEntriesCount();

        $cases = [
            [['name_ar' => 'أ'], 'code'],
            [['code' => 'Not A Code!', 'name_ar' => 'أ'], 'code'],
            [['code' => 'UPPER', 'name_ar' => 'أ'], 'code'],
            [['code' => str_repeat('a', 65), 'name_ar' => 'أ'], 'code'],
            [['code' => $this->code()], 'name_ar'],
            [['code' => $this->code(), 'name_ar' => '   '], 'name_ar'],
            [['code' => $this->code(), 'name_ar' => str_repeat('ا', 256)], 'name_ar'],
            [['code' => $this->code(), 'name_ar' => 'أ', 'name_en' => str_repeat('a', 256)], 'name_en'],
            [['code' => $this->code(), 'name_ar' => 'أ', 'display_order' => -1], 'display_order'],
        ];

        foreach ($cases as [$payload, $field]) {
            $this->postJson(self::URL, $payload)->assertStatus(422)->assertJsonValidationErrors([$field]);
        }

        $this->assertSame(0, Specialty::query()->count());
        $this->assertSame($auditBefore, $this->auditEntriesCount());
    }

    public function test_update_metadata_changes_only_descriptive_fields_and_preserves_identity(): void
    {
        $this->actingAsReferenceManager();
        $created = $this->create()->json();

        $this->patchJson(self::URL."/{$created['id']}", [
            'name_ar' => 'اسم معدل',
            'name_en' => 'Renamed',
            'display_order' => 9,
            'code' => 'attempted_code_change',
            'is_active' => false,
            'expected_version' => 1,
        ])->assertOk()
            ->assertJsonPath('id', $created['id'])
            ->assertJsonPath('code', $created['code'])
            ->assertJsonPath('is_active', true)
            ->assertJsonPath('name_ar', 'اسم معدل')
            ->assertJsonPath('name_en', 'Renamed')
            ->assertJsonPath('display_order', 9)
            ->assertJsonPath('version', 2);
    }

    public function test_update_metadata_with_a_stale_version_is_409_and_mutates_nothing(): void
    {
        $this->actingAsReferenceManager();
        $created = $this->create()->json();
        $auditBefore = $this->auditEntriesCount();

        $this->patchJson(self::URL."/{$created['id']}", ['name_ar' => 'ب', 'expected_version' => 7])->assertStatus(409);

        $this->assertSame($created['name_ar'], Specialty::query()->findOrFail($created['id'])->name_ar);
        $this->assertSame($auditBefore, $this->auditEntriesCount());
    }

    public function test_deactivate_and_activate_preserve_identity_and_keep_the_row_readable(): void
    {
        $this->actingAsReferenceManager();
        $created = $this->create()->json();

        $this->postJson(self::URL."/{$created['id']}/deactivate", ['expected_version' => 1])
            ->assertOk()->assertJsonPath('is_active', false)->assertJsonPath('version', 2);

        $this->getJson(self::URL."/{$created['id']}")->assertOk()
            ->assertJsonPath('is_active', false)->assertJsonPath('code', $created['code']);
        $this->assertContains($created['id'], array_column($this->getJson(self::URL)->json('data'), 'id'),
            'an inactive specialty stays listed — deactivation is not deletion');

        $this->postJson(self::URL."/{$created['id']}/activate", ['expected_version' => 2])
            ->assertOk()->assertJsonPath('is_active', true)->assertJsonPath('id', $created['id']);
        $this->postJson(self::URL."/{$created['id']}/activate", ['expected_version' => 2])->assertStatus(409);
    }

    public function test_there_is_no_hard_delete(): void
    {
        $this->actingAsReferenceManager();
        $created = $this->create()->json();

        $this->deleteJson(self::URL."/{$created['id']}")->assertStatus(405);
        $this->assertTrue(Specialty::query()->whereKey($created['id'])->exists());
    }

    public function test_an_unknown_specialty_is_404(): void
    {
        $this->actingAsReferenceManager();

        $this->getJson(self::URL.'/'.Str::uuid7())->assertNotFound();
        $this->postJson(self::URL.'/'.Str::uuid7().'/deactivate', ['expected_version' => 1])->assertNotFound();
    }

    // ---------------------------------------------------------------------
    // Security
    // ---------------------------------------------------------------------

    public function test_unauthenticated_requests_are_401(): void
    {
        $this->getJson(self::URL)->assertUnauthorized();
        $this->postJson(self::URL, ['code' => $this->code(), 'name_ar' => 'أ'])->assertUnauthorized();
    }

    public function test_a_principal_without_reference_permissions_is_403(): void
    {
        $this->actingAs($this->createPrincipal(), 'web');

        $this->getJson(self::URL)->assertForbidden();
        $this->postJson(self::URL, ['code' => $this->code(), 'name_ar' => 'أ'])->assertForbidden();
    }

    public function test_a_reference_viewer_can_read_but_not_write(): void
    {
        $specialty = $this->specialtyRow();
        $this->actingAsReferenceViewer();

        $this->getJson(self::URL)->assertOk();
        $this->getJson(self::URL."/{$specialty->id}")->assertOk();
        $this->postJson(self::URL, ['code' => $this->code(), 'name_ar' => 'أ'])->assertForbidden();
        $this->patchJson(self::URL."/{$specialty->id}", ['name_ar' => 'ب', 'expected_version' => 1])->assertForbidden();
        $this->postJson(self::URL."/{$specialty->id}/deactivate", ['expected_version' => 1])->assertForbidden();

        $this->assertNull($this->latestAuditEntryFor('reference.specialty.create'));
        $this->assertTrue(Specialty::query()->findOrFail($specialty->id)->is_active);
    }

    public function test_hr_permissions_alone_do_not_grant_specialty_administration(): void
    {
        $principal = $this->createPrincipal();
        $this->assignRole($principal, $this->createRoleWithPermissions(HumanResourcesPermissionCatalog::ALL));
        $this->actingAs($principal, 'web');

        $this->getJson(self::URL)->assertForbidden();
        $this->postJson(self::URL, ['code' => $this->code(), 'name_ar' => 'أ'])->assertForbidden();
    }

    public function test_the_shared_reference_manage_permission_authorizes_administration(): void
    {
        $this->actingAsReferenceManager();

        $this->create()->assertCreated();
        $this->getJson(self::URL)->assertOk();
    }

    // ---------------------------------------------------------------------
    // Audit
    // ---------------------------------------------------------------------

    public function test_every_successful_mutation_is_audited_with_stable_identifiers(): void
    {
        $principal = $this->actingAsReferenceManager();
        $code = $this->code();

        $id = $this->postJson(self::URL, ['code' => $code, 'name_ar' => 'قديم', 'name_en' => 'Old'])->assertCreated()->json('id');
        $entry = $this->latestAuditEntryFor('reference.specialty.create');
        $this->assertSame($principal->id, $entry->actor_principal_id);
        $this->assertSame('reference_specialty', $entry->target_type);
        $this->assertSame($id, $entry->target_id);
        $this->assertEquals(['code' => $code, 'name_ar' => 'قديم', 'name_en' => 'Old', 'display_order' => 0], $entry->changes);

        $this->patchJson(self::URL."/{$id}", ['name_ar' => 'جديد', 'expected_version' => 1])->assertOk();
        $entry = $this->latestAuditEntryFor('reference.specialty.metadata.update');
        $this->assertSame($id, $entry->target_id);
        $this->assertEquals(['from' => 'قديم', 'to' => 'جديد'], $entry->changes['name_ar']);

        $this->postJson(self::URL."/{$id}/deactivate", ['expected_version' => 2])->assertOk();
        $entry = $this->latestAuditEntryFor('reference.specialty.deactivate');
        $this->assertSame($id, $entry->target_id);
        $this->assertEquals(['is_active' => ['from' => true, 'to' => false]], $entry->changes);

        $this->postJson(self::URL."/{$id}/activate", ['expected_version' => 3])->assertOk();
        $entry = $this->latestAuditEntryFor('reference.specialty.activate');
        $this->assertSame($id, $entry->target_id);
        $this->assertEquals(['is_active' => ['from' => false, 'to' => true]], $entry->changes);

        foreach (['reference.specialty.create', 'reference.specialty.metadata.update', 'reference.specialty.deactivate', 'reference.specialty.activate'] as $action) {
            $payload = json_encode([$this->latestAuditEntryFor($action)->changes, $this->latestAuditEntryFor($action)->metadata]);
            foreach (['person', 'employee', 'employment', 'national_id'] as $employeeKey) {
                $this->assertStringNotContainsString($employeeKey, $payload, 'the catalog audit carries no employee semantics');
            }
        }
    }

    public function test_rejected_writes_leave_no_success_audit(): void
    {
        $this->actingAsReferenceManager();
        $created = $this->create()->json();
        $auditBefore = $this->auditEntriesCount();

        $this->postJson(self::URL, ['code' => $created['code'], 'name_ar' => 'مكرر'])->assertStatus(422);
        $this->patchJson(self::URL."/{$created['id']}", ['name_ar' => 'ب', 'expected_version' => 5])->assertStatus(409);
        $this->postJson(self::URL."/{$created['id']}/deactivate", ['expected_version' => 5])->assertStatus(409);
        $this->postJson(self::URL."/{$created['id']}/activate", [])->assertStatus(422);

        $this->assertSame($auditBefore, $this->auditEntriesCount());
    }

    // ---------------------------------------------------------------------
    // S06 reporting mapping compatibility
    // ---------------------------------------------------------------------

    public function test_creating_a_specialty_never_creates_a_cadre_mapping_or_category(): void
    {
        $this->actingAsReferenceManager();
        $mappingsBefore = SpecialtyCadreCategoryMapping::query()->count();
        $categoriesBefore = MonthlyCadreCategory::query()->count();

        $id = $this->create()->json('id');

        $this->assertSame($mappingsBefore, SpecialtyCadreCategoryMapping::query()->count(), 'no automatic cadre classification');
        $this->assertSame($categoriesBefore, MonthlyCadreCategory::query()->count());
        $this->getJson(self::URL."/{$id}/cadre-category-mappings")->assertOk()->assertJsonCount(0);
        $this->assertNull(app(ResolveSpecialtyCadreCategoryAsOf::class)->__invoke(Specialty::query()->findOrFail($id), '2026-06-01'),
            'an unmapped specialty stays UNRESOLVED — never an "Other" category');
    }

    public function test_an_administered_specialty_participates_in_the_existing_mapping_and_survives_deactivation(): void
    {
        $this->actingAsReferenceManager();
        $id = $this->create()->json('id');
        $category = MonthlyCadreCategory::query()->where('is_active', true)->firstOrFail();

        $mappingId = $this->postJson(self::URL."/{$id}/cadre-category-mappings", [
            'cadre_category_id' => $category->id, 'effective_from' => '2026-01-01',
        ])->assertCreated()->json('id');

        $this->postJson(self::URL."/{$id}/deactivate", ['expected_version' => 1])->assertOk();

        $this->assertTrue(SpecialtyCadreCategoryMapping::query()->whereKey($mappingId)->exists(), 'deactivation never cascades to the mapping');
        $this->getJson(self::URL."/{$id}/cadre-category-mappings")->assertOk()->assertJsonCount(1)->assertJsonPath('0.id', $mappingId);
        $resolved = app(ResolveSpecialtyCadreCategoryAsOf::class)->__invoke(Specialty::query()->findOrFail($id), '2026-06-01');
        $this->assertSame($category->id, $resolved?->id, 'historical/reporting resolution is intact after deactivation');
    }

    public function test_a_mapped_specialty_cannot_be_hard_deleted_at_the_database_level(): void
    {
        $specialty = $this->specialtyRow();
        $category = MonthlyCadreCategory::query()->firstOrFail();
        SpecialtyCadreCategoryMapping::query()->create([
            'specialty_id' => $specialty->id, 'cadre_category_id' => $category->id, 'effective_from' => '2026-01-01',
        ]);

        try {
            DB::transaction(fn () => DB::table('ref.specialties')->where('id', $specialty->id)->delete());
            $this->fail('a referenced specialty must not be deletable');
        } catch (QueryException) {
        }

        $this->assertTrue(Specialty::query()->whereKey($specialty->id)->exists());
    }

    public function test_the_mapping_stays_governed_by_its_own_permission(): void
    {
        $specialty = $this->specialtyRow();
        $category = MonthlyCadreCategory::query()->firstOrFail();
        $this->actingAsReferenceViewer();

        $this->postJson(self::URL."/{$specialty->id}/cadre-category-mappings", [
            'cadre_category_id' => $category->id, 'effective_from' => '2026-01-01',
        ])->assertForbidden();
    }

    // ---------------------------------------------------------------------
    // Scope guards (ADR-S25-001 B/D/E/G, §12, §13)
    // ---------------------------------------------------------------------

    public function test_no_employee_assignment_history_or_duplicate_catalog_was_introduced(): void
    {
        $specialtyColumns = DB::table('information_schema.columns')
            ->where('column_name', 'like', '%specialt%')
            ->whereNotIn('table_schema', ['pg_catalog', 'information_schema'])
            ->get(['table_schema', 'table_name', 'column_name'])
            ->map(fn ($c) => "{$c->table_schema}.{$c->table_name}.{$c->column_name}")->sort()->values()->all();
        $this->assertSame(['ref.specialty_cadre_category_mappings.specialty_id'], $specialtyColumns,
            'no person/employment specialty FK or any other specialty column exists');

        $specialtyTables = DB::table('information_schema.tables')->where('table_name', 'like', '%specialt%')
            ->get(['table_schema', 'table_name'])->map(fn ($t) => "{$t->table_schema}.{$t->table_name}")->sort()->values()->all();
        $this->assertSame(['ref.specialties', 'ref.specialty_cadre_category_mappings'], $specialtyTables,
            'no assignment, history, academic or professional specialty table');

        $this->assertSame(0, DB::table('information_schema.tables')->where('table_schema', 'reporting')->count(), 'no Report 1 implementation');
    }

    public function test_no_other_or_seed_value_exists(): void
    {
        $this->assertSame(0, Specialty::query()->count());
        $this->assertSame(0, DB::table('migrations')->where('migration', 'like', '%specialt%')->where('migration', 'like', '%seed%')->count(),
            'no specialty seed migration');
    }

    public function test_no_hr_route_and_no_employee_360_surface_exposes_specialty(): void
    {
        $hrSpecialtyRoutes = collect(app('router')->getRoutes())
            ->filter(fn ($route) => str_starts_with($route->uri(), 'api/v1/hr') && str_contains($route->uri(), 'specialt'));
        $this->assertCount(0, $hrSpecialtyRoutes);

        $frontend = base_path('../frontend/src');
        if (is_dir($frontend)) {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($frontend, \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                $this->assertStringNotContainsStringIgnoringCase('specialt', (string) file_get_contents($file->getPathname()),
                    "{$file->getFilename()}: no Employee 360 / frontend specialty display in S25");
            }
        }
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    private function code(): string
    {
        return 'spec_'.Str::lower(Str::random(10));
    }

    private function create(array $overrides = []): TestResponse
    {
        return $this->postJson(self::URL, ['code' => $this->code(), 'name_ar' => 'تخصص تجريبي', ...$overrides])->assertCreated();
    }

    private function specialtyRow(): Specialty
    {
        return Specialty::create(['code' => $this->code(), 'name_ar' => 'تخصص']);
    }
}
