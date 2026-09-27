<?php

namespace Tests\Feature\Organization;

use App\Modules\Organization\Infrastructure\Authorization\OrganizationPermissionCatalog;
use App\Modules\Organization\Infrastructure\Persistence\Eloquent\OrganizationalUnit;
use App\Modules\Security\Infrastructure\Persistence\Eloquent\Principal;
use Illuminate\Support\Str;
use Tests\Feature\Audit\AuditTestCase;

/**
 * Base class for S07 Organization feature tests. Inherits AuditTestCase's fixture and audit-read
 * helpers (createPrincipal/createRoleWithPermissions/assignRole/latestAuditEntryFor/...), mirroring
 * ReferenceTestCase's shape exactly (spec §39).
 */
abstract class OrganizationTestCase extends AuditTestCase
{
    /** A principal with both organization.view and organization.manage via a fresh active role. */
    protected function actingAsOrganizationManager(): Principal
    {
        $principal = $this->createPrincipal();
        $role = $this->createRoleWithPermissions(OrganizationPermissionCatalog::ALL);
        $this->assignRole($principal, $role);
        $this->actingAs($principal, 'web');

        return $principal;
    }

    /** A principal with organization.view only — cannot mutate. */
    protected function actingAsOrganizationViewer(): Principal
    {
        $principal = $this->createPrincipal();
        $role = $this->createRoleWithPermissions([OrganizationPermissionCatalog::ORGANIZATION_VIEW]);
        $this->assignRole($principal, $role);
        $this->actingAs($principal, 'web');

        return $principal;
    }

    protected function createUnit(?string $name = null, ?string $parentId = null): OrganizationalUnit
    {
        $unit = new OrganizationalUnit([
            'name' => $name ?? 'Unit '.Str::random(8),
            'parent_id' => $parentId,
            'is_active' => true,
        ]);
        $unit->save();

        return $unit->refresh();
    }
}
