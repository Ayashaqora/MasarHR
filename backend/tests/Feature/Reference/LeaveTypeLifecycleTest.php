<?php

namespace Tests\Feature\Reference;

use Illuminate\Support\Str;

/**
 * S13 lifecycle + audit + security coverage for LeaveType
 * (docs/reference-catalog-administration-foundation-specification.md §6/§24), mirroring
 * GenderLifecycleTest's depth exactly — this is genuinely new S13 capability, not a re-test of
 * existing S05/S06 behavior.
 */
class LeaveTypeLifecycleTest extends ReferenceTestCase
{
    private function code(): string
    {
        return 'lt_'.Str::lower(Str::random(10));
    }

    public function test_create_succeeds_and_is_audited(): void
    {
        $this->actingAsReferenceManager();
        $code = $this->code();

        $response = $this->postJson('/api/v1/reference/leave-types', [
            'code' => $code,
            'name_ar' => 'قيمة مرجعية',
            'name_en' => 'Reference Value',
        ])->assertCreated();

        $response->assertJsonPath('code', $code)
            ->assertJsonPath('is_active', true)
            ->assertJsonPath('version', 1);

        $entry = $this->latestAuditEntryFor('reference.leave_type.create');
        $this->assertNotNull($entry);
        $this->assertSame($response->json('id'), $entry->target_id);
        $this->assertSame($code, $entry->changes['code']);
    }

    public function test_create_rejects_duplicate_code(): void
    {
        $this->actingAsReferenceManager();
        $code = $this->code();

        $this->postJson('/api/v1/reference/leave-types', ['code' => $code, 'name_ar' => 'أ'])->assertCreated();
        $this->postJson('/api/v1/reference/leave-types', ['code' => $code, 'name_ar' => 'ب'])
            ->assertStatus(422);
    }

    public function test_create_validation_rejects_invalid_code_format(): void
    {
        $this->actingAsReferenceManager();

        $this->postJson('/api/v1/reference/leave-types', ['code' => 'Not A Valid Code!', 'name_ar' => 'أ'])
            ->assertStatus(422);
    }

    public function test_create_is_rejected_without_manage_permission_and_appends_no_audit_entry(): void
    {
        $this->actingAsReferenceViewer();
        $before = $this->auditEntriesCount();

        $this->postJson('/api/v1/reference/leave-types', [
            'code' => $this->code(),
            'name_ar' => 'قيمة',
        ])->assertStatus(403);

        $this->assertNull($this->latestAuditEntryFor('reference.leave_type.create'));
        $this->assertGreaterThan($before, $this->auditEntriesCount());
    }

    public function test_create_is_rejected_when_unauthenticated(): void
    {
        $this->postJson('/api/v1/reference/leave-types', ['code' => $this->code(), 'name_ar' => 'قيمة'])
            ->assertStatus(401);
    }

    public function test_index_requires_view_permission(): void
    {
        // Authenticated but with zero reference permissions (no role assigned) — distinct from the
        // unauthenticated 401 case above.
        $principal = $this->createPrincipal();
        $this->actingAs($principal, 'web');

        $this->getJson('/api/v1/reference/leave-types')->assertStatus(403);
    }

    public function test_update_metadata_succeeds_and_is_audited(): void
    {
        $this->actingAsReferenceManager();
        $created = $this->postJson('/api/v1/reference/leave-types', ['code' => $this->code(), 'name_ar' => 'قديم'])
            ->assertCreated()->json();

        $response = $this->patchJson("/api/v1/reference/leave-types/{$created['id']}", [
            'name_ar' => 'جديد',
            'name_en' => 'New',
            'display_order' => 5,
            'expected_version' => 1,
        ])->assertOk();

        $response->assertJsonPath('name_ar', 'جديد')->assertJsonPath('version', 2);

        $entry = $this->latestAuditEntryFor('reference.leave_type.metadata.update');
        $this->assertSame('قديم', $entry->changes['name_ar']['from']);
        $this->assertSame('جديد', $entry->changes['name_ar']['to']);
    }

    public function test_update_metadata_never_changes_code_or_is_active(): void
    {
        $this->actingAsReferenceManager();
        $code = $this->code();
        $created = $this->postJson('/api/v1/reference/leave-types', ['code' => $code, 'name_ar' => 'أ'])
            ->assertCreated()->json();

        $response = $this->patchJson("/api/v1/reference/leave-types/{$created['id']}", [
            'name_ar' => 'ب',
            'expected_version' => 1,
        ])->assertOk();

        // updateMetadata's own validation accepts no `code`/`is_active` fields at all — the route
        // never exposes a way to change either, mirroring S05 §9 ("never code, never is_active").
        $response->assertJsonPath('code', $code)->assertJsonPath('is_active', true);
    }

    public function test_update_metadata_with_stale_version_is_rejected(): void
    {
        $this->actingAsReferenceManager();
        $created = $this->postJson('/api/v1/reference/leave-types', ['code' => $this->code(), 'name_ar' => 'أ'])
            ->assertCreated()->json();

        $this->patchJson("/api/v1/reference/leave-types/{$created['id']}", [
            'name_ar' => 'ب',
            'expected_version' => 999,
        ])->assertStatus(409);
    }

    public function test_deactivate_then_activate_round_trip_is_audited(): void
    {
        $this->actingAsReferenceManager();
        $created = $this->postJson('/api/v1/reference/leave-types', ['code' => $this->code(), 'name_ar' => 'أ'])
            ->assertCreated()->json();

        $deactivated = $this->postJson("/api/v1/reference/leave-types/{$created['id']}/deactivate", [
            'expected_version' => 1,
        ])->assertOk()->json();

        $this->assertFalse($deactivated['is_active']);
        $this->assertNotNull($this->latestAuditEntryFor('reference.leave_type.deactivate'));

        // Deactivated values remain readable (S05 §9, reused unchanged) — not deleted, not hidden.
        $this->getJson("/api/v1/reference/leave-types/{$created['id']}")->assertOk()->assertJsonPath('is_active', false);

        $activated = $this->postJson("/api/v1/reference/leave-types/{$created['id']}/activate", [
            'expected_version' => 2,
        ])->assertOk()->json();

        $this->assertTrue($activated['is_active']);
        $this->assertNotNull($this->latestAuditEntryFor('reference.leave_type.activate'));
    }

    public function test_no_hard_delete_route_exists(): void
    {
        $this->actingAsReferenceManager();
        $created = $this->postJson('/api/v1/reference/leave-types', ['code' => $this->code(), 'name_ar' => 'أ'])
            ->assertCreated()->json();

        $this->deleteJson("/api/v1/reference/leave-types/{$created['id']}")->assertStatus(405);
    }
}
