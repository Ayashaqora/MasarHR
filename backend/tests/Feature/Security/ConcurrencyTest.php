<?php

namespace Tests\Feature\Security;

use App\Modules\Security\Infrastructure\Authorization\PermissionCatalog;
use Illuminate\Support\Str;

/** §18 of the S03 authorization: optimistic concurrency at the HTTP layer. */
class ConcurrencyTest extends SecurityTestCase
{
    public function test_a_stale_expected_version_on_the_display_name_endpoint_returns_409(): void
    {
        $actor = $this->createSecurityAdministrator();
        $target = $this->createPrincipal();
        $this->actingAs($actor, 'web');

        // First update succeeds and bumps the version...
        $this->patchJson("/api/v1/security/principals/{$target->id}/display-name", [
            'display_name' => 'First Update',
            'expected_version' => $target->version,
        ])->assertOk();

        // ...a second request still carrying the old version is a conflict, not a silent overwrite.
        $this->patchJson("/api/v1/security/principals/{$target->id}/display-name", [
            'display_name' => 'Stale Update',
            'expected_version' => $target->version,
        ])->assertStatus(409);
    }

    public function test_a_stale_expected_version_on_the_username_endpoint_returns_409(): void
    {
        $actor = $this->createSecurityAdministrator();
        $target = $this->createPrincipal();
        $this->actingAs($actor, 'web');

        $this->patchJson("/api/v1/security/principals/{$target->id}/username", [
            'username' => 'freshname',
            'expected_version' => $target->version,
        ])->assertOk();

        $this->patchJson("/api/v1/security/principals/{$target->id}/username", [
            'username' => 'stalename',
            'expected_version' => $target->version,
        ])->assertStatus(409);
    }

    public function test_role_metadata_update_honors_optimistic_concurrency(): void
    {
        $actor = $this->createPrincipal();
        $manageRole = $this->createRoleWithPermissions([PermissionCatalog::ROLES_MANAGE, PermissionCatalog::ROLES_VIEW]);
        $this->assignRole($actor, $manageRole);
        $this->actingAs($actor, 'web');

        $role = $this->createRoleWithPermissions([]);

        $this->patchJson("/api/v1/security/roles/{$role->id}", [
            'name_ar' => 'محدث',
            'name_en' => 'Updated',
            'expected_version' => $role->version,
        ])->assertOk();

        $this->patchJson("/api/v1/security/roles/{$role->id}", [
            'name_ar' => 'قديم',
            'name_en' => 'Stale',
            'expected_version' => $role->version,
        ])->assertStatus(409);
    }

    public function test_a_non_existent_principal_still_returns_404_rather_than_a_conflict(): void
    {
        $actor = $this->createSecurityAdministrator();
        $this->actingAs($actor, 'web');

        $this->patchJson('/api/v1/security/principals/'.Str::uuid7().'/display-name', [
            'display_name' => 'Whoever',
            'expected_version' => 1,
        ])->assertStatus(404);
    }
}
