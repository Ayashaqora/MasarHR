<?php

namespace Tests\Feature\Security;

use App\Modules\Security\Application\Commands\ChangePrincipalStatus;
use App\Modules\Security\Application\Commands\DeactivateRole;
use App\Modules\Security\Application\Commands\RemoveRoleFromPrincipal;
use App\Modules\Security\Application\Commands\RevokePermissionFromRole;
use App\Modules\Security\Application\Security\SecurityAdministrationGuard;
use App\Modules\Security\Domain\Exceptions\LastSecurityAdministratorException;
use App\Modules\Security\Domain\PrincipalStatus;
use App\Modules\Security\Domain\SecurityAdministrationCapability;
use App\Modules\Security\Infrastructure\Authorization\EffectivePermissionsResolver;
use App\Modules\Security\Infrastructure\Authorization\PermissionCatalog;
use App\Modules\Security\Infrastructure\Persistence\Eloquent\Permission;

/** §17 / §30 LAST ADMIN of the S03 authorization. */
class LastAdminTest extends SecurityTestCase
{
    public function test_disabling_the_only_capable_administrator_is_refused(): void
    {
        $admin = $this->createSecurityAdministrator();
        $guard = app(SecurityAdministrationGuard::class);

        $this->expectException(LastSecurityAdministratorException::class);
        $guard->run(fn () => app(ChangePrincipalStatus::class)->handle($admin, PrincipalStatus::Disabled, $admin->version));
    }

    public function test_disabling_one_of_two_capable_administrators_is_allowed(): void
    {
        $adminA = $this->createSecurityAdministrator();
        $adminB = $this->createSecurityAdministrator();
        $guard = app(SecurityAdministrationGuard::class);

        $result = $guard->run(fn () => app(ChangePrincipalStatus::class)->handle($adminA, PrincipalStatus::Disabled, $adminA->version));

        $this->assertSame(PrincipalStatus::Disabled, $result->status);
        // adminB remains active and capable.
        $this->assertSame(PrincipalStatus::Active, $adminB->refresh()->status);
    }

    public function test_removing_the_final_required_role_from_the_last_administrator_is_refused(): void
    {
        $admin = $this->createSecurityAdministrator();
        $role = $admin->roleAssignments()->firstOrFail()->role;
        $guard = app(SecurityAdministrationGuard::class);

        $this->expectException(LastSecurityAdministratorException::class);
        $guard->run(fn () => app(RemoveRoleFromPrincipal::class)->handle($admin, $role));
    }

    public function test_deactivating_the_only_role_that_grants_administration_capability_is_refused(): void
    {
        $admin = $this->createPrincipal();
        $role = $this->createRoleWithPermissions(PermissionCatalog::ALL);
        $this->assignRole($admin, $role);
        $guard = app(SecurityAdministrationGuard::class);

        $this->expectException(LastSecurityAdministratorException::class);
        $guard->run(fn () => app(DeactivateRole::class)->handle($role, $role->version));
    }

    public function test_revoking_a_required_permission_from_the_only_capable_roles_last_holder_is_refused(): void
    {
        $admin = $this->createPrincipal();
        $role = $this->createRoleWithPermissions(PermissionCatalog::ALL);
        $this->assignRole($admin, $role);
        $guard = app(SecurityAdministrationGuard::class);

        $permission = Permission::query()
            ->where('code', PermissionCatalog::USERS_STATUS_MANAGE)->firstOrFail();

        $this->expectException(LastSecurityAdministratorException::class);
        $guard->run(fn () => app(RevokePermissionFromRole::class)->handle($role, $permission));
    }

    public function test_removing_a_non_essential_role_from_an_administrator_with_another_capable_role_is_allowed(): void
    {
        $admin = $this->createPrincipal();
        $capableRole = $this->createRoleWithPermissions(PermissionCatalog::ALL);
        $extraRole = $this->createRoleWithPermissions([PermissionCatalog::PERMISSIONS_VIEW]);
        $this->assignRole($admin, $capableRole);
        $this->assignRole($admin, $extraRole);
        $guard = app(SecurityAdministrationGuard::class);

        // Removing the extra, non-essential role must not be blocked by the invariant.
        $guard->run(fn () => app(RemoveRoleFromPrincipal::class)->handle($admin, $extraRole));

        $this->assertTrue(SecurityAdministrationCapability::isCapable(
            app(EffectivePermissionsResolver::class)->resolve($admin),
        ));
    }

    public function test_a_second_concurrent_disable_does_not_leave_zero_capable_administrators(): void
    {
        // Two capable administrators. Simulate two "disable" operations racing: the guard's
        // pg_advisory_xact_lock serializes them, so the second sees the post-first-commit state
        // and must be refused rather than the invariant being silently violated.
        $adminA = $this->createSecurityAdministrator();
        $adminB = $this->createSecurityAdministrator();
        $guard = app(SecurityAdministrationGuard::class);

        $guard->run(fn () => app(ChangePrincipalStatus::class)->handle($adminA, PrincipalStatus::Disabled, $adminA->version));

        $this->expectException(LastSecurityAdministratorException::class);
        $guard->run(fn () => app(ChangePrincipalStatus::class)->handle($adminB, PrincipalStatus::Disabled, $adminB->version));
    }

    public function test_a_refused_operation_leaves_the_principal_untouched(): void
    {
        $admin = $this->createSecurityAdministrator();
        $originalVersion = $admin->version;
        $guard = app(SecurityAdministrationGuard::class);

        try {
            $guard->run(fn () => app(ChangePrincipalStatus::class)->handle($admin, PrincipalStatus::Disabled, $admin->version));
            $this->fail('Expected LastSecurityAdministratorException.');
        } catch (LastSecurityAdministratorException) {
            // expected
        }

        $fresh = $admin->fresh();
        $this->assertSame(PrincipalStatus::Active, $fresh->status);
        $this->assertSame($originalVersion, $fresh->version);
    }

    public function test_the_status_endpoint_returns_409_when_disabling_would_leave_no_capable_administrator(): void
    {
        $admin = $this->createSecurityAdministrator();
        $this->actingAs($admin, 'web');

        $this->patchJson("/api/v1/security/principals/{$admin->id}/status", [
            'status' => 'DISABLED',
            'expected_version' => $admin->version,
        ])->assertStatus(409);
    }
}
