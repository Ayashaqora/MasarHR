<?php

namespace Tests\Feature\Reference;

use Illuminate\Support\Str;

/** Full S05 lifecycle + audit + security coverage for Gender, the representative "rich" family. */
class GenderLifecycleTest extends ReferenceTestCase
{
    private function code(): string
    {
        return 'g_'.Str::lower(Str::random(10));
    }

    public function test_create_succeeds_and_is_audited(): void
    {
        $this->actingAsReferenceManager();
        $code = $this->code();

        $response = $this->postJson('/api/v1/reference/genders', [
            'code' => $code,
            'name_ar' => 'ذكر',
            'name_en' => 'Male',
        ])->assertCreated();

        $response->assertJsonPath('code', $code)
            ->assertJsonPath('is_active', true)
            ->assertJsonPath('version', 1);

        $entry = $this->latestAuditEntryFor('reference.gender.create');
        $this->assertNotNull($entry);
        $this->assertSame($response->json('id'), $entry->target_id);
        $this->assertSame($code, $entry->changes['code']);
    }

    public function test_create_rejects_duplicate_code(): void
    {
        $this->actingAsReferenceManager();
        $code = $this->code();

        $this->postJson('/api/v1/reference/genders', ['code' => $code, 'name_ar' => 'أ'])->assertCreated();
        $this->postJson('/api/v1/reference/genders', ['code' => $code, 'name_ar' => 'ب'])
            ->assertStatus(422);
    }

    public function test_create_is_rejected_without_manage_permission_and_appends_no_audit_entry(): void
    {
        $this->actingAsReferenceViewer();
        $before = $this->auditEntriesCount();

        $this->postJson('/api/v1/reference/genders', [
            'code' => $this->code(),
            'name_ar' => 'ذكر',
        ])->assertStatus(403);

        // A denied action does append a SECURITY_EVENT (RequirePermission), but never a
        // reference.gender.create MUTATION entry.
        $this->assertNull($this->latestAuditEntryFor('reference.gender.create'));
        $this->assertGreaterThan($before, $this->auditEntriesCount());
    }

    public function test_create_is_rejected_when_unauthenticated(): void
    {
        $this->postJson('/api/v1/reference/genders', ['code' => $this->code(), 'name_ar' => 'ذكر'])
            ->assertStatus(401);
    }

    public function test_update_metadata_succeeds_and_is_audited(): void
    {
        $this->actingAsReferenceManager();
        $created = $this->postJson('/api/v1/reference/genders', ['code' => $this->code(), 'name_ar' => 'قديم'])
            ->assertCreated()->json();

        $response = $this->patchJson("/api/v1/reference/genders/{$created['id']}", [
            'name_ar' => 'جديد',
            'name_en' => 'New',
            'display_order' => 5,
            'expected_version' => 1,
        ])->assertOk();

        $response->assertJsonPath('name_ar', 'جديد')->assertJsonPath('version', 2);

        $entry = $this->latestAuditEntryFor('reference.gender.metadata.update');
        $this->assertSame('قديم', $entry->changes['name_ar']['from']);
        $this->assertSame('جديد', $entry->changes['name_ar']['to']);
    }

    public function test_update_metadata_with_stale_version_is_rejected(): void
    {
        $this->actingAsReferenceManager();
        $created = $this->postJson('/api/v1/reference/genders', ['code' => $this->code(), 'name_ar' => 'أ'])
            ->assertCreated()->json();

        $this->patchJson("/api/v1/reference/genders/{$created['id']}", [
            'name_ar' => 'ب',
            'expected_version' => 999,
        ])->assertStatus(409);
    }

    public function test_deactivate_then_activate_round_trip_is_audited(): void
    {
        $this->actingAsReferenceManager();
        $created = $this->postJson('/api/v1/reference/genders', ['code' => $this->code(), 'name_ar' => 'أ'])
            ->assertCreated()->json();

        $deactivated = $this->postJson("/api/v1/reference/genders/{$created['id']}/deactivate", [
            'expected_version' => 1,
        ])->assertOk()->json();

        $this->assertFalse($deactivated['is_active']);
        $this->assertNotNull($this->latestAuditEntryFor('reference.gender.deactivate'));

        // Deactivated values remain readable (§9) — not deleted, not hidden from show().
        $this->getJson("/api/v1/reference/genders/{$created['id']}")->assertOk()->assertJsonPath('is_active', false);

        $activated = $this->postJson("/api/v1/reference/genders/{$created['id']}/activate", [
            'expected_version' => 2,
        ])->assertOk()->json();

        $this->assertTrue($activated['is_active']);
        $this->assertNotNull($this->latestAuditEntryFor('reference.gender.activate'));
    }

    public function test_index_lists_the_seeded_baseline_values(): void
    {
        $this->actingAsReferenceViewer();

        $response = $this->getJson('/api/v1/reference/genders')->assertOk();
        $codes = collect($response->json('data'))->pluck('code')->all();

        $this->assertContains('male', $codes);
        $this->assertContains('female', $codes);
    }
}
