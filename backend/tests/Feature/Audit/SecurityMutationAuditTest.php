<?php

namespace Tests\Feature\Audit;

use App\Modules\Audit\Domain\Category;
use App\Modules\Audit\Domain\Outcome;
use App\Modules\Platform\Domain\ActorType;
use App\Modules\Security\Infrastructure\Authorization\PermissionCatalog as Perm;
use App\Modules\Security\Infrastructure\Persistence\Eloquent\Permission;

/**
 * S04 §16/§17/AUD-01..AUD-08: every retrofitted S03 mutation produces exactly one SUCCEEDED
 * MUTATION audit entry, correctly shaped (action/target_type/target_id/actor/changes/metadata),
 * with no password value or hash ever present.
 */
class SecurityMutationAuditTest extends AuditTestCase
{
    private function assertMutationAudited(string $action, string $expectedTargetType, string $expectedTargetId, string $actingPrincipalId): void
    {
        $entry = $this->latestAuditEntryFor($action);

        $this->assertNotNull($entry, "expected a MUTATION audit entry for action \"{$action}\"");
        $this->assertSame(Category::Mutation, $entry->category);
        $this->assertSame(Outcome::Succeeded, $entry->outcome);
        $this->assertSame($expectedTargetType, $entry->target_type);
        $this->assertSame($expectedTargetId, $entry->target_id);
        $this->assertSame(ActorType::Human, $entry->actor_type);
        $this->assertSame($actingPrincipalId, $entry->actor_principal_id);
        $this->assertNull($entry->actor_label);
        $this->assertNotNull($entry->correlation_id);

        $flatPayload = array_merge($entry->changes ?? [], $entry->metadata ?? []);
        foreach ($flatPayload as $key => $value) {
            $this->assertStringNotContainsStringIgnoringCase('password', (string) $key);
            if (is_string($value)) {
                $this->assertNotSame('CorrectHorseBattery9!', $value, 'a plaintext password value must never be audited');
            }
        }
    }

    public function test_create_principal_produces_one_aggregated_mutation_entry_errata_04(): void
    {
        $admin = $this->createSecurityAdministrator();
        $this->actingAs($admin, 'web');
        $countBefore = $this->auditEntriesCount();

        $username = $this->uniqueUsername('created');
        $response = $this->postJson('/api/v1/security/principals', [
            'username' => $username,
            'display_name' => 'Created Principal',
            'password' => self::VALID_PASSWORD,
        ])->assertStatus(201);

        $newId = $response->json('data.id') ?? $response->json('id');
        $this->assertNotNull($newId);

        // ERRATA-04: CreatePrincipal + SetInitialPassword is ONE logical mutation — exactly one new
        // audit entry, not two, and no password value anywhere in it.
        $this->assertSame($countBefore + 1, $this->auditEntriesCount());
        $this->assertMutationAudited('security.principal.create', 'security_principal', $newId, $admin->getKey());
    }

    public function test_change_principal_username_is_audited_with_from_to_changes(): void
    {
        $admin = $this->createSecurityAdministrator();
        $subject = $this->createPrincipal();
        $this->actingAs($admin, 'web');
        $newUsername = $this->uniqueUsername('renamed');

        $this->patchJson("/api/v1/security/principals/{$subject->id}/username", [
            'username' => $newUsername,
            'expected_version' => $subject->version,
        ])->assertOk();

        $this->assertMutationAudited('security.principal.username.change', 'security_principal', $subject->id, $admin->getKey());
        $entry = $this->latestAuditEntryFor('security.principal.username.change');
        $this->assertSame($newUsername, $entry->changes['username']['to']);
    }

    public function test_change_principal_display_name_is_audited(): void
    {
        $admin = $this->createSecurityAdministrator();
        $subject = $this->createPrincipal();
        $this->actingAs($admin, 'web');

        $this->patchJson("/api/v1/security/principals/{$subject->id}/display-name", [
            'display_name' => 'A New Display Name',
            'expected_version' => $subject->version,
        ])->assertOk();

        $this->assertMutationAudited('security.principal.display_name.change', 'security_principal', $subject->id, $admin->getKey());
    }

    public function test_change_principal_status_is_audited(): void
    {
        $admin = $this->createSecurityAdministrator();
        $subject = $this->createPrincipal();
        $this->actingAs($admin, 'web');

        $this->patchJson("/api/v1/security/principals/{$subject->id}/status", [
            'status' => 'DISABLED',
            'expected_version' => $subject->version,
        ])->assertOk();

        $this->assertMutationAudited('security.principal.status.change', 'security_principal', $subject->id, $admin->getKey());
    }

    public function test_reset_password_administratively_is_audited_with_no_password_value(): void
    {
        $admin = $this->createSecurityAdministrator();
        $subject = $this->createPrincipal();
        $this->actingAs($admin, 'web');

        $this->putJson("/api/v1/security/principals/{$subject->id}/password", [
            'password' => 'AnotherValidPassphrase2!',
        ])->assertNoContent();

        $this->assertMutationAudited('security.credential.password.reset_administrative', 'security_credential', $subject->id, $admin->getKey());
        $entry = $this->latestAuditEntryFor('security.credential.password.reset_administrative');
        $this->assertNull($entry->changes, '§16: no changes payload for a credential rotation');
    }

    public function test_change_own_password_is_audited_with_no_password_value(): void
    {
        $admin = $this->createSecurityAdministrator();
        $this->actingAs($admin, 'web');

        $this->putJson('/api/v1/auth/password', [
            'current_password' => self::VALID_PASSWORD,
            'password' => 'AnotherValidPassphrase2!',
        ])->assertNoContent();

        $this->assertMutationAudited('security.credential.password.change', 'security_credential', $admin->getKey(), $admin->getKey());
        $entry = $this->latestAuditEntryFor('security.credential.password.change');
        $this->assertNull($entry->changes, '§16: no changes payload for a credential rotation');
    }

    public function test_create_role_is_audited(): void
    {
        $admin = $this->createSecurityAdministrator();
        $this->actingAs($admin, 'web');
        $code = 'role_'.mb_strtolower(str()->random(8));

        $response = $this->postJson('/api/v1/security/roles', [
            'code' => $code,
            'name_ar' => 'دور',
            'name_en' => 'Role',
            'description' => null,
        ])->assertStatus(201);

        $roleId = $response->json('data.id') ?? $response->json('id');

        $this->assertMutationAudited('security.role.create', 'security_role', $roleId, $admin->getKey());
    }

    public function test_update_role_metadata_is_audited(): void
    {
        $admin = $this->createSecurityAdministrator();
        $role = $this->createRoleWithPermissions([]);
        $this->actingAs($admin, 'web');

        $this->patchJson("/api/v1/security/roles/{$role->id}", [
            'name_ar' => 'محدث',
            'name_en' => 'Updated',
            'description' => 'updated',
            'expected_version' => $role->version,
        ])->assertOk();

        $this->assertMutationAudited('security.role.metadata.update', 'security_role', $role->id, $admin->getKey());
    }

    public function test_activate_role_is_audited(): void
    {
        $admin = $this->createSecurityAdministrator();
        $role = $this->createRoleWithPermissions([], active: false);
        $this->actingAs($admin, 'web');

        $this->postJson("/api/v1/security/roles/{$role->id}/activate", [
            'expected_version' => $role->version,
        ])->assertOk();

        $this->assertMutationAudited('security.role.activate', 'security_role', $role->id, $admin->getKey());
    }

    public function test_deactivate_role_is_audited(): void
    {
        $admin = $this->createSecurityAdministrator();
        $role = $this->createRoleWithPermissions([]); // grants nothing, so deactivating it can't affect capability.
        $this->actingAs($admin, 'web');

        $this->postJson("/api/v1/security/roles/{$role->id}/deactivate", [
            'expected_version' => $role->version,
        ])->assertOk();

        $this->assertMutationAudited('security.role.deactivate', 'security_role', $role->id, $admin->getKey());
    }

    public function test_assign_role_to_principal_is_audited_with_composite_target_id(): void
    {
        $admin = $this->createSecurityAdministrator();
        $subject = $this->createPrincipal();
        $role = $this->createRoleWithPermissions([]);
        $this->actingAs($admin, 'web');

        $this->postJson("/api/v1/security/principals/{$subject->id}/roles", [
            'role_id' => $role->id,
        ])->assertStatus(201);

        $this->assertMutationAudited('security.role_assignment.create', 'security_principal_role', "{$subject->id}:{$role->id}", $admin->getKey());
    }

    public function test_remove_role_from_principal_is_audited_with_composite_target_id(): void
    {
        $admin = $this->createSecurityAdministrator();
        $subject = $this->createPrincipal();
        $role = $this->createRoleWithPermissions([]);
        $this->assignRole($subject, $role);
        $this->actingAs($admin, 'web');

        $this->deleteJson("/api/v1/security/principals/{$subject->id}/roles/{$role->id}")->assertNoContent();

        $this->assertMutationAudited('security.role_assignment.remove', 'security_principal_role', "{$subject->id}:{$role->id}", $admin->getKey());
    }

    public function test_grant_permission_to_role_is_audited_with_composite_target_id(): void
    {
        $admin = $this->createSecurityAdministrator();
        $role = $this->createRoleWithPermissions([]);
        $permission = Permission::query()->where('code', Perm::PERMISSIONS_VIEW)->firstOrFail();
        $this->actingAs($admin, 'web');

        $this->postJson("/api/v1/security/roles/{$role->id}/permissions", [
            'permission_id' => $permission->id,
        ])->assertStatus(201);

        $this->assertMutationAudited('security.role_permission.grant', 'security_role_permission', "{$role->id}:{$permission->id}", $admin->getKey());
    }

    public function test_revoke_permission_from_role_is_audited_with_composite_target_id(): void
    {
        $admin = $this->createSecurityAdministrator();
        $role = $this->createRoleWithPermissions([Perm::PERMISSIONS_VIEW]);
        $permission = Permission::query()->where('code', Perm::PERMISSIONS_VIEW)->firstOrFail();
        $this->actingAs($admin, 'web');

        $this->deleteJson("/api/v1/security/roles/{$role->id}/permissions/{$permission->id}")->assertNoContent();

        $this->assertMutationAudited('security.role_permission.revoke', 'security_role_permission', "{$role->id}:{$permission->id}", $admin->getKey());
    }
}
