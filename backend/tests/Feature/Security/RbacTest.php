<?php

namespace Tests\Feature\Security;

use App\Modules\Security\Application\Commands\ActivateRole;
use App\Modules\Security\Application\Commands\CreateRole;
use App\Modules\Security\Application\Commands\GrantPermissionToRole;
use App\Modules\Security\Domain\Exceptions\DuplicatePermissionGrantException;
use App\Modules\Security\Domain\Exceptions\DuplicateRoleAssignmentException;
use App\Modules\Security\Domain\Exceptions\DuplicateRoleCodeException;
use App\Modules\Security\Infrastructure\Authorization\EffectivePermissionsResolver;
use App\Modules\Security\Infrastructure\Authorization\PermissionCatalog;
use App\Modules\Security\Infrastructure\Persistence\Eloquent\Permission;

/** §30 RBAC. */
class RbacTest extends SecurityTestCase
{
    public function test_creating_a_role_persists_it_active_and_non_system(): void
    {
        $role = app(CreateRole::class)->handle('hr_clerk', 'كاتب موارد بشرية', 'HR Clerk', 'Front-office clerk role.');

        $this->assertTrue($role->is_active);
        $this->assertFalse($role->is_system);
        $this->assertSame(1, $role->version);
    }

    public function test_a_duplicate_role_code_is_rejected(): void
    {
        app(CreateRole::class)->handle('dup_role', 'أ', 'A', null);

        $this->expectException(DuplicateRoleCodeException::class);
        app(CreateRole::class)->handle('dup_role', 'ب', 'B', null);
    }

    public function test_permissions_seeded_by_s03_all_belong_to_the_security_module(): void
    {
        $permissions = Permission::query()->whereIn('code', PermissionCatalog::ALL)->get();

        $this->assertCount(count(PermissionCatalog::ALL), $permissions);
        foreach ($permissions as $permission) {
            $this->assertSame('security', $permission->module);
        }
    }

    public function test_assigning_a_role_grants_its_permissions_to_the_principal(): void
    {
        $principal = $this->createPrincipal();
        $role = $this->createRoleWithPermissions([PermissionCatalog::USERS_VIEW, PermissionCatalog::ROLES_VIEW]);

        $this->assignRole($principal, $role);

        $effective = app(EffectivePermissionsResolver::class)->resolve($principal);
        $this->assertEqualsCanonicalizing([PermissionCatalog::USERS_VIEW, PermissionCatalog::ROLES_VIEW], $effective);
    }

    public function test_duplicate_role_assignment_is_rejected(): void
    {
        $principal = $this->createPrincipal();
        $role = $this->createRoleWithPermissions([PermissionCatalog::USERS_VIEW]);
        $this->assignRole($principal, $role);

        $this->expectException(DuplicateRoleAssignmentException::class);
        $this->assignRole($principal, $role);
    }

    public function test_duplicate_permission_grant_is_rejected(): void
    {
        $role = app(CreateRole::class)->handle('grant_test', 'أ', 'A', null);
        $permission = Permission::query()->where('code', PermissionCatalog::USERS_VIEW)->firstOrFail();

        app(GrantPermissionToRole::class)->handle($role, $permission);

        $this->expectException(DuplicatePermissionGrantException::class);
        app(GrantPermissionToRole::class)->handle($role, $permission);
    }

    public function test_an_inactive_role_contributes_nothing_to_effective_permissions(): void
    {
        $principal = $this->createPrincipal();
        $role = $this->createRoleWithPermissions([PermissionCatalog::USERS_VIEW], active: false);
        $this->assignRole($principal, $role);

        $effective = app(EffectivePermissionsResolver::class)->resolve($principal);
        $this->assertSame([], $effective);
    }

    public function test_reactivating_a_role_restores_its_contribution_to_effective_permissions(): void
    {
        $principal = $this->createPrincipal();
        $role = $this->createRoleWithPermissions([PermissionCatalog::USERS_VIEW], active: false);
        $this->assignRole($principal, $role);

        app(ActivateRole::class)->handle($role, $role->version);

        $effective = app(EffectivePermissionsResolver::class)->resolve($principal->refresh());
        $this->assertSame([PermissionCatalog::USERS_VIEW], $effective);
    }

    public function test_effective_permissions_are_the_union_across_multiple_roles(): void
    {
        $principal = $this->createPrincipal();
        $roleA = $this->createRoleWithPermissions([PermissionCatalog::USERS_VIEW]);
        $roleB = $this->createRoleWithPermissions([PermissionCatalog::ROLES_VIEW, PermissionCatalog::PERMISSIONS_VIEW]);
        $this->assignRole($principal, $roleA);
        $this->assignRole($principal, $roleB);

        $effective = app(EffectivePermissionsResolver::class)->resolve($principal);
        $this->assertEqualsCanonicalizing(
            [PermissionCatalog::USERS_VIEW, PermissionCatalog::ROLES_VIEW, PermissionCatalog::PERMISSIONS_VIEW],
            $effective,
        );
    }

    public function test_a_principal_with_no_roles_has_no_permissions_default_deny(): void
    {
        $principal = $this->createPrincipal();

        $this->assertSame([], app(EffectivePermissionsResolver::class)->resolve($principal));
    }

    public function test_an_unrecognised_permission_code_never_matches(): void
    {
        $principal = $this->createPrincipal();
        $role = $this->createRoleWithPermissions([PermissionCatalog::USERS_VIEW]);
        $this->assignRole($principal, $role);

        $this->assertFalse(app(EffectivePermissionsResolver::class)->has($principal, 'security.nonexistent.permission'));
    }

    public function test_direct_api_calls_without_the_required_permission_are_rejected_regardless_of_authentication(): void
    {
        $principal = $this->createPrincipal();
        $this->actingAs($principal, 'web');

        $this->getJson('/api/v1/security/roles')->assertStatus(403);
        $this->postJson('/api/v1/security/roles', ['code' => 'x', 'name_ar' => 'أ', 'name_en' => 'A'])->assertStatus(403);
    }

    public function test_role_and_permission_administration_endpoints_require_roles_manage(): void
    {
        $actor = $this->createPrincipal();
        $viewerRole = $this->createRoleWithPermissions([PermissionCatalog::ROLES_VIEW]);
        $this->assignRole($actor, $viewerRole);
        $this->actingAs($actor, 'web');

        // Can view...
        $this->getJson('/api/v1/security/roles')->assertOk();
        // ...but not manage.
        $this->postJson('/api/v1/security/roles', ['code' => 'nope', 'name_ar' => 'أ', 'name_en' => 'A'])->assertStatus(403);
    }
}
