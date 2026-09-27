<?php

namespace Tests\Feature\Reference;

use App\Modules\Reference\Application\Commands\CreateEmploymentStatusCategory;
use Illuminate\Support\Str;

class EmploymentStatusDetailLifecycleTest extends ReferenceTestCase
{
    private function code(): string
    {
        return 'esd_'.Str::lower(Str::random(10));
    }

    private function categoryId(): string
    {
        return app(CreateEmploymentStatusCategory::class)
            ->handle('cat_'.Str::lower(Str::random(10)), 'فئة', null, null)
            ->getKey();
    }

    public function test_create_succeeds_with_a_valid_category_and_is_audited(): void
    {
        $this->actingAsReferenceManager();
        $categoryId = $this->categoryId();
        $code = $this->code();

        $response = $this->postJson('/api/v1/reference/employment-status-details', [
            'category_id' => $categoryId,
            'code' => $code,
            'name_ar' => 'قيد الخدمة',
        ])->assertCreated();

        $response->assertJsonPath('code', $code)->assertJsonPath('category_id', $categoryId);

        $entry = $this->latestAuditEntryFor('reference.employment_status_detail.create');
        $this->assertSame($categoryId, $entry->changes['category_id']);
    }

    public function test_create_with_an_unknown_category_is_rejected_with_404(): void
    {
        $this->actingAsReferenceManager();

        $this->postJson('/api/v1/reference/employment-status-details', [
            'category_id' => (string) Str::uuid7(),
            'code' => $this->code(),
            'name_ar' => 'أ',
        ])->assertStatus(404);
    }

    public function test_update_metadata_does_not_accept_a_category_change(): void
    {
        $this->actingAsReferenceManager();
        $categoryId = $this->categoryId();
        $created = $this->postJson('/api/v1/reference/employment-status-details', [
            'category_id' => $categoryId,
            'code' => $this->code(),
            'name_ar' => 'أ',
        ])->assertCreated()->json();

        // §19: category_id is immutable after creation — updateMetadata's validated payload has
        // no category_id field at all, so sending one is simply ignored.
        $updated = $this->patchJson("/api/v1/reference/employment-status-details/{$created['id']}", [
            'name_ar' => 'ب',
            'category_id' => (string) Str::uuid7(),
            'expected_version' => 1,
        ])->assertOk()->json();

        $this->assertSame($categoryId, $updated['category_id']);
    }

    public function test_deactivate_is_audited(): void
    {
        $this->actingAsReferenceManager();
        $created = $this->postJson('/api/v1/reference/employment-status-details', [
            'category_id' => $this->categoryId(),
            'code' => $this->code(),
            'name_ar' => 'أ',
        ])->assertCreated()->json();

        $this->postJson("/api/v1/reference/employment-status-details/{$created['id']}/deactivate", [
            'expected_version' => 1,
        ])->assertOk()->assertJsonPath('is_active', false);

        $this->assertNotNull($this->latestAuditEntryFor('reference.employment_status_detail.deactivate'));
    }
}
