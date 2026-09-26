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
        // again by S08 §18, extended again by S09 §17): by S09 it also contains the two
        // Reference-module, two Organization-module, and five HumanResources-module permission
        // codes seeded alongside PermissionCatalog::ALL — which already includes
        // ORGANIZATION_SCOPES_MANAGE itself, since that permission is owned by the Security
        // module, not a separate module catalog. Reference/Organization/HumanResources codes are
        // still named literally here (not imported from any other module) so this Security test
        // does not depend on another module's own catalog class.
        $this->assertEqualsCanonicalizing(
            [
                ...PermissionCatalog::ALL,
                'reference.view', 'reference.manage', 'organization.view', 'organization.manage',
                'hr.persons.view', 'hr.persons.create', 'hr.employment_relationships.view',
                'hr.employment_relationships.create', 'hr.employment_relationships.end',
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
