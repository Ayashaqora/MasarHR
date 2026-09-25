<?php

namespace Tests\Feature\Reference;

use Illuminate\Support\Str;

/** S05 lifecycle + audit coverage for MaritalStatus (mirrors GenderLifecycleTest's depth). */
class MaritalStatusLifecycleTest extends ReferenceTestCase
{
    private function code(): string
    {
        return 'ms_'.Str::lower(Str::random(10));
    }

    public function test_create_succeeds_and_is_audited(): void
    {
        $this->actingAsReferenceManager();
        $code = $this->code();

        $response = $this->postJson('/api/v1/reference/marital-statuses', [
            'code' => $code,
            'name_ar' => 'قيمة',
        ])->assertCreated();

        $response->assertJsonPath('code', $code)->assertJsonPath('version', 1);

        $entry = $this->latestAuditEntryFor('reference.marital_status.create');
        $this->assertSame($response->json('id'), $entry->target_id);
    }

    public function test_create_is_rejected_without_manage_permission(): void
    {
        $this->actingAsReferenceViewer();

        $this->postJson('/api/v1/reference/marital-statuses', ['code' => $this->code(), 'name_ar' => 'قيمة'])
            ->assertStatus(403);
    }

    public function test_update_metadata_with_stale_version_is_rejected(): void
    {
        $this->actingAsReferenceManager();
        $created = $this->postJson('/api/v1/reference/marital-statuses', ['code' => $this->code(), 'name_ar' => 'أ'])
            ->assertCreated()->json();

        $this->patchJson("/api/v1/reference/marital-statuses/{$created['id']}", [
            'name_ar' => 'ب',
            'expected_version' => 999,
        ])->assertStatus(409);
    }

    public function test_deactivate_then_activate_round_trip_is_audited(): void
    {
        $this->actingAsReferenceManager();
        $created = $this->postJson('/api/v1/reference/marital-statuses', ['code' => $this->code(), 'name_ar' => 'أ'])
            ->assertCreated()->json();

        $deactivated = $this->postJson("/api/v1/reference/marital-statuses/{$created['id']}/deactivate", [
            'expected_version' => 1,
        ])->assertOk()->json();
        $this->assertFalse($deactivated['is_active']);
        $this->assertNotNull($this->latestAuditEntryFor('reference.marital_status.deactivate'));

        $activated = $this->postJson("/api/v1/reference/marital-statuses/{$created['id']}/activate", [
            'expected_version' => 2,
        ])->assertOk()->json();
        $this->assertTrue($activated['is_active']);
        $this->assertNotNull($this->latestAuditEntryFor('reference.marital_status.activate'));
    }
}
