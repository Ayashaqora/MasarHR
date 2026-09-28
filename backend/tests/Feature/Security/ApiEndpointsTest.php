<?php

namespace Tests\Feature\Security;

use App\Modules\Security\Infrastructure\Authorization\PermissionCatalog;
use App\Modules\Security\Infrastructure\Persistence\Eloquent\Permission;
use App\Modules\Security\Infrastructure\Persistence\Eloquent\Principal;

/** §20-22 of the S03 authorization: the Security administration API surface and HTTP semantics. */
class ApiEndpointsTest extends SecurityTestCase
{
    private function actingAsFullAdministrator(): Principal
    {
        $admin = $this->createSecurityAdministrator();
        $this->actingAs($admin, 'web');

        return $admin;
    }

    public function test_permissions_index_lists_the_baseline_catalog(): void
    {
        $this->actingAsFullAdministrator();

        $response = $this->getJson('/api/v1/security/permissions')->assertOk();
        $codes = collect($response->json('data'))->pluck('code')->all();

        // security.permissions is a cross-module catalog (S05 §16, extended by S07 §17, extended
        // again by S08 §18, extended again by S09 §17, extended again by S10 §13, extended again by
        // S11 §16, extended again by S12 §17, extended again by S14 §19 per ADR-S14-002, extended
        // again by S16 §S16.14 per ADR-S16-001, extended again by S20 §S20.12 per ADR-S20-001, and
        // by S21 §S21.13 per ADR-S21-001, and by S22 §S22.13 per ADR-S22-001): by S22 it also contains the two Reference-module,
        // two Organization-module, five S09 HumanResources-module, two S10 HumanResources-module,
        // two S11 HumanResources-module, three S12 HumanResources-module, one S14
        // HumanResources-module, three S16 HumanResources-module, two S20 HumanResources-module, and
        // two S21, and two S22 HumanResources-module permission codes seeded
        // alongside PermissionCatalog::ALL — which already includes ORGANIZATION_SCOPES_MANAGE
        // itself, since that permission is owned by the Security module, not a separate module
        // catalog. Reference/Organization/HumanResources codes are still named literally here (not
        // imported from any other module) so this Security test does not depend on another module's
        // own catalog class.
        $this->assertEqualsCanonicalizing(
            [
                ...PermissionCatalog::ALL,
                'reference.view', 'reference.manage', 'organization.view', 'organization.manage',
                'hr.persons.view', 'hr.persons.create', 'hr.employment_relationships.view',
                'hr.employment_relationships.create', 'hr.employment_relationships.end',
                'hr.employment_relationships.transfer',
                'hr.employment_status_periods.view', 'hr.employment_status_periods.record',
                'hr.organizational_placement_periods.view', 'hr.organizational_placement_periods.record',
                'hr.full_secondment_periods.view', 'hr.full_secondment_periods.start', 'hr.full_secondment_periods.end',
                'hr.workplace_assignment_periods.view', 'hr.workplace_assignment_periods.start', 'hr.workplace_assignment_periods.end',
                'hr.employment_category_periods.view', 'hr.employment_category_periods.record',
                'hr.employment_contract_periods.view', 'hr.employment_contract_periods.record',
                'hr.employment_job_title_periods.view', 'hr.employment_job_title_periods.record',
            ],
            $codes,
        );
    }

    public function test_role_lifecycle_create_activate_deactivate_via_http(): void
    {
        $this->actingAsFullAdministrator();

        $created = $this->postJson('/api/v1/security/roles', [
            'code' => 'clerk_role',
            'name_ar' => 'كاتب',
            'name_en' => 'Clerk',
        ])->assertCreated()->json();

        $this->assertTrue($created['is_active']);

        $this->postJson("/api/v1/security/roles/{$created['id']}/deactivate", ['expected_version' => $created['version']])
            ->assertOk()->assertJsonPath('is_active', false);

        $refreshed = $this->getJson("/api/v1/security/roles/{$created['id']}")->json();

        $this->postJson("/api/v1/security/roles/{$created['id']}/activate", ['expected_version' => $refreshed['version']])
            ->assertOk()->assertJsonPath('is_active', true);
    }

    public function test_granting_and_revoking_a_permission_via_http(): void
    {
        $this->actingAsFullAdministrator();
        $role = $this->createRoleWithPermissions([]);
        $permission = Permission::query()->where('code', PermissionCatalog::PERMISSIONS_VIEW)->firstOrFail();

        $this->postJson("/api/v1/security/roles/{$role->id}/permissions", ['permission_id' => $permission->id])
            ->assertStatus(201);

        $this->deleteJson("/api/v1/security/roles/{$role->id}/permissions/{$permission->id}")
            ->assertNoContent();
    }

    public function test_assigning_and_removing_a_role_via_http(): void
    {
        $this->actingAsFullAdministrator();
        $target = $this->createPrincipal();
        $role = $this->createRoleWithPermissions([PermissionCatalog::PERMISSIONS_VIEW]);

        $this->postJson("/api/v1/security/principals/{$target->id}/roles", ['role_id' => $role->id])
            ->assertStatus(201);

        $this->getJson("/api/v1/security/principals/{$target->id}/permissions")
            ->assertOk()->assertJson(['permissions' => [PermissionCatalog::PERMISSIONS_VIEW]]);

        $this->deleteJson("/api/v1/security/principals/{$target->id}/roles/{$role->id}")
            ->assertNoContent();

        $this->getJson("/api/v1/security/principals/{$target->id}/permissions")
            ->assertOk()->assertJson(['permissions' => []]);
    }

    public function test_validation_failure_returns_422_with_field_errors(): void
    {
        $this->actingAsFullAdministrator();

        $this->postJson('/api/v1/security/principals', [
            'username' => '',
            'display_name' => '',
            'password' => 'x',
        ])->assertStatus(422)->assertJsonValidationErrors(['username', 'display_name', 'password']);
    }

    public function test_error_responses_never_leak_sql_or_stack_traces(): void
    {
        $this->actingAsFullAdministrator();

        $body = $this->postJson('/api/v1/security/principals', [
            'username' => '',
            'display_name' => '',
            'password' => 'x',
        ])->getContent();

        foreach (['SQLSTATE', '.php:', 'Stack trace', 'PDOException', 'select *'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $body);
        }
    }

    public function test_read_endpoints_require_only_the_view_permission_not_manage(): void
    {
        $actor = $this->createPrincipal();
        $viewerRole = $this->createRoleWithPermissions([PermissionCatalog::USERS_VIEW]);
        $this->assignRole($actor, $viewerRole);
        $this->actingAs($actor, 'web');

        $target = $this->createPrincipal();

        $this->getJson("/api/v1/security/principals/{$target->id}")->assertOk();
        $this->getJson('/api/v1/security/principals')->assertOk();
    }
}
