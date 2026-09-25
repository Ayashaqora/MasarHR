<?php

namespace Tests\Feature\Audit;

/**
 * ERRATA-03: a StaleVersionException/optimistic-concurrency conflict is not a SECURITY_EVENT by
 * default, and produces no successful MUTATION audit entry either — nothing was committed.
 */
class StaleVersionNoAuditTest extends AuditTestCase
{
    public function test_a_stale_version_conflict_on_username_writes_no_audit_entry_at_all(): void
    {
        $admin = $this->createSecurityAdministrator();
        $this->actingAs($admin, 'web');
        $countBefore = $this->auditEntriesCount();

        $this->patchJson("/api/v1/security/principals/{$admin->id}/username", [
            'username' => $this->uniqueUsername('staleuser'),
            'expected_version' => $admin->version + 1,
        ])->assertStatus(409);

        $this->assertSame($countBefore, $this->auditEntriesCount());
    }

    public function test_a_stale_version_conflict_on_role_metadata_writes_no_audit_entry_at_all(): void
    {
        $admin = $this->createSecurityAdministrator();
        $role = $this->createRoleWithPermissions([]);
        $this->actingAs($admin, 'web');
        $countBefore = $this->auditEntriesCount();

        $this->patchJson("/api/v1/security/roles/{$role->id}", [
            'name_ar' => 'محدث',
            'name_en' => 'Updated',
            'description' => null,
            'expected_version' => $role->version + 1,
        ])->assertStatus(409);

        $this->assertSame($countBefore, $this->auditEntriesCount());
    }
}
