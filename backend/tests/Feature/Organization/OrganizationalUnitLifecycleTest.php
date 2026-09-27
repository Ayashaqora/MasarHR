<?php

namespace Tests\Feature\Organization;

/** S07 lifecycle + audit coverage for OrganizationalUnit (mirrors the S06 lifecycle tests' depth). */
class OrganizationalUnitLifecycleTest extends OrganizationTestCase
{
    public function test_create_a_root_unit_succeeds_and_is_audited(): void
    {
        $this->actingAsOrganizationManager();

        $response = $this->postJson('/api/v1/organization/units', ['name' => 'Ministry Headquarters'])
            ->assertCreated();

        $response->assertJsonPath('name', 'Ministry Headquarters')
            ->assertJsonPath('parent_id', null)
            ->assertJsonPath('is_active', true)
            ->assertJsonPath('version', 1);

        $entry = $this->latestAuditEntryFor('organization.unit.create');
        $this->assertSame($response->json('id'), $entry->target_id);
    }

    public function test_create_a_child_unit_with_a_parent_succeeds(): void
    {
        $this->actingAsOrganizationManager();
        $parent = $this->createUnit('Parent Directorate');

        $response = $this->postJson('/api/v1/organization/units', [
            'name' => 'Child Department',
            'parent_id' => $parent->id,
        ])->assertCreated();

        $response->assertJsonPath('parent_id', $parent->id);
    }

    public function test_create_with_a_nonexistent_parent_is_rejected(): void
    {
        $this->actingAsOrganizationManager();

        $this->postJson('/api/v1/organization/units', [
            'name' => 'Orphan',
            'parent_id' => '00000000-0000-0000-0000-000000000000',
        ])->assertStatus(404);
    }

    public function test_create_is_rejected_without_manage_permission(): void
    {
        $this->actingAsOrganizationViewer();

        $this->postJson('/api/v1/organization/units', ['name' => 'Blocked'])
            ->assertStatus(403);
    }

    public function test_create_is_rejected_without_authentication(): void
    {
        $this->postJson('/api/v1/organization/units', ['name' => 'Unauthenticated'])
            ->assertStatus(401);
    }

    public function test_create_without_a_name_is_a_standardized_validation_error(): void
    {
        $this->actingAsOrganizationManager();

        $this->postJson('/api/v1/organization/units', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name']);
    }

    public function test_rename_succeeds_and_is_audited(): void
    {
        $this->actingAsOrganizationManager();
        $unit = $this->createUnit('Old Name');

        $response = $this->patchJson("/api/v1/organization/units/{$unit->id}", [
            'name' => 'New Name',
            'expected_version' => 1,
        ])->assertOk();

        $response->assertJsonPath('name', 'New Name')->assertJsonPath('version', 2);
        $this->assertNotNull($this->latestAuditEntryFor('organization.unit.rename'));
    }

    public function test_rename_with_a_stale_version_is_rejected(): void
    {
        $this->actingAsOrganizationManager();
        $unit = $this->createUnit();

        $this->patchJson("/api/v1/organization/units/{$unit->id}", [
            'name' => 'Whatever',
            'expected_version' => 999,
        ])->assertStatus(409);
    }

    public function test_activate_then_deactivate_round_trip_is_audited_and_uses_no_cascade(): void
    {
        $this->actingAsOrganizationManager();
        $parent = $this->createUnit('Parent');
        $child = $this->createUnit('Child', $parent->id);

        $deactivated = $this->postJson("/api/v1/organization/units/{$parent->id}/deactivate", [
            'expected_version' => 1,
        ])->assertOk()->json();
        $this->assertFalse($deactivated['is_active']);
        $this->assertNotNull($this->latestAuditEntryFor('organization.unit.deactivate'));

        // No cascade (spec §8 D19): the child's own is_active is untouched by its parent's
        // deactivation.
        $this->assertTrue($child->refresh()->is_active);

        $activated = $this->postJson("/api/v1/organization/units/{$parent->id}/activate", [
            'expected_version' => 2,
        ])->assertOk()->json();
        $this->assertTrue($activated['is_active']);
        $this->assertNotNull($this->latestAuditEntryFor('organization.unit.activate'));
    }

    public function test_deactivating_a_parent_with_active_children_is_allowed(): void
    {
        // Spec §8 D20: resolved by direct S05 CORRECTIVE-01 precedent, not a new restriction.
        $this->actingAsOrganizationManager();
        $parent = $this->createUnit('Parent');
        $this->createUnit('Active Child', $parent->id);

        $this->postJson("/api/v1/organization/units/{$parent->id}/deactivate", ['expected_version' => 1])
            ->assertOk()
            ->assertJsonPath('is_active', false);
    }

    public function test_no_hard_delete_route_exists(): void
    {
        $this->actingAsOrganizationManager();
        $unit = $this->createUnit();

        $this->deleteJson("/api/v1/organization/units/{$unit->id}")->assertStatus(405);
    }
}
