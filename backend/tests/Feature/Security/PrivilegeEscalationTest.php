<?php

namespace Tests\Feature\Security;

use App\Modules\Security\Application\Commands\ChangePrincipalStatus;
use App\Modules\Security\Domain\PrincipalStatus;
use App\Modules\Security\Infrastructure\Authorization\PermissionCatalog;
use App\Modules\Security\Infrastructure\Persistence\Eloquent\Permission;

/** §30 PRIVILEGE ESCALATION / §16 of the S03 authorization. */
class PrivilegeEscalationTest extends SecurityTestCase
{
    public function test_an_ordinary_authenticated_principal_cannot_assign_a_privileged_role_to_itself(): void
    {
        $principal = $this->createPrincipal();
        $privilegedRole = $this->createRoleWithPermissions(PermissionCatalog::ALL);
        $this->actingAs($principal, 'web');

        $this->postJson("/api/v1/security/principals/{$principal->id}/roles", ['role_id' => $privilegedRole->id])
            ->assertStatus(403);
    }

    public function test_an_ordinary_authenticated_principal_cannot_grant_permissions_to_any_role(): void
    {
        $principal = $this->createPrincipal();
        $role = $this->createRoleWithPermissions([]);
        $this->actingAs($principal, 'web');

        $permission = Permission::query()
            ->where('code', PermissionCatalog::USERS_VIEW)->firstOrFail();

        $this->postJson("/api/v1/security/roles/{$role->id}/permissions", ['permission_id' => $permission->id])
            ->assertStatus(403);
    }

    public function test_a_status_update_payload_cannot_smuggle_privileged_fields_via_mass_assignment(): void
    {
        // Principal::$fillable (via #[Fillable]) never includes `version`, so even a handcrafted
        // payload cannot bump it outside the optimistic-concurrency path a command controls.
        $principal = $this->createPrincipal();

        $principal->fill(['version' => 999]);

        $this->assertNotSame(999, $principal->version, '"version" must not be mass-assignable');
    }

    public function test_a_generic_mass_assignment_payload_cannot_change_principal_status(): void
    {
        // S03 correction order §1: `status` is not in Principal::$fillable (via #[Fillable]), so a
        // handcrafted/generic payload routed through Eloquent mass assignment must not be able to
        // move it, even though it is a plain string column with no other guard around fill().
        $principal = $this->createPrincipal();
        $this->assertSame(PrincipalStatus::Active, $principal->status);

        $principal->fill(['status' => PrincipalStatus::Disabled->value]);

        $this->assertSame(
            PrincipalStatus::Active,
            $principal->status,
            '"status" must not be mass-assignable — it moved via fill() alone, outside ChangePrincipalStatus'
        );
        $this->assertSame(
            PrincipalStatus::Active,
            $principal->fresh()->status,
            'the database must be unaffected by the mass-assignment attempt'
        );
    }

    public function test_the_authorized_change_principal_status_command_still_changes_status(): void
    {
        // The hardening above must not have broken the one legitimate way to change status.
        $principal = $this->createPrincipal();
        $this->assertSame(PrincipalStatus::Active, $principal->status);

        $updated = app(ChangePrincipalStatus::class)->handle($principal, PrincipalStatus::Disabled, $principal->version);

        $this->assertSame(PrincipalStatus::Disabled, $updated->status);
        $this->assertSame(PrincipalStatus::Disabled, $principal->fresh()->status);
    }

    public function test_updating_ones_own_display_name_does_not_require_or_grant_security_administration_permissions(): void
    {
        // Profile editing must not imply permission to modify roles/permissions/status (§16). S03
        // has no "edit my own profile" endpoint at all — display-name changes go through the
        // Security administration API and require security.users.update like any other principal's.
        $principal = $this->createPrincipal();
        $this->actingAs($principal, 'web');

        $this->patchJson("/api/v1/security/principals/{$principal->id}/display-name", [
            'display_name' => 'New Name',
            'expected_version' => $principal->version,
        ])->assertStatus(403);
    }

    public function test_an_unauthorized_principal_cannot_read_the_full_principal_list(): void
    {
        $principal = $this->createPrincipal();
        $this->actingAs($principal, 'web');

        $this->getJson('/api/v1/security/principals')->assertStatus(403);
    }
}
