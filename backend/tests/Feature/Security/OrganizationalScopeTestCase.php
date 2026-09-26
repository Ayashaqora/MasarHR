<?php

namespace Tests\Feature\Security;

use App\Modules\Organization\Infrastructure\Persistence\Eloquent\OrganizationalUnit;
use App\Modules\Security\Application\Commands\GrantOrganizationalScope;
use App\Modules\Security\Infrastructure\Authorization\PermissionCatalog;
use App\Modules\Security\Infrastructure\Persistence\Eloquent\OrganizationalScopeGrant;
use App\Modules\Security\Infrastructure\Persistence\Eloquent\Principal;
use Illuminate\Support\Str;
use Tests\Feature\Audit\AuditTestCase;

/**
 * Base class for S08 organizational-access-scope feature tests. Inherits AuditTestCase's fixture
 * and audit-read helpers (createPrincipal/createRoleWithPermissions/assignRole/latestAuditEntryFor/
 * ...), mirroring OrganizationTestCase's shape exactly (spec §21).
 */
abstract class OrganizationalScopeTestCase extends AuditTestCase
{
    /** A principal with security.organization_scopes.manage via a fresh active role. */
    protected function actingAsScopeAdministrator(): Principal
    {
        $principal = $this->createPrincipal();
        $role = $this->createRoleWithPermissions([PermissionCatalog::ORGANIZATION_SCOPES_MANAGE]);
        $this->assignRole($principal, $role);
        $this->actingAs($principal, 'web');

        return $principal;
    }

    protected function createUnit(?string $name = null, ?string $parentId = null, bool $active = true): OrganizationalUnit
    {
        $unit = new OrganizationalUnit([
            'name' => $name ?? 'Unit '.Str::random(8),
            'parent_id' => $parentId,
            'is_active' => $active,
        ]);
        $unit->save();

        return $unit->refresh();
    }

    protected function grantGlobalScope(Principal $principal, ?Principal $grantedBy = null): OrganizationalScopeGrant
    {
        return app(GrantOrganizationalScope::class)->handle($principal, 'GLOBAL', null, $grantedBy);
    }

    protected function grantUnitScope(Principal $principal, OrganizationalUnit $unit, ?Principal $grantedBy = null): OrganizationalScopeGrant
    {
        return app(GrantOrganizationalScope::class)->handle($principal, 'UNIT', $unit, $grantedBy);
    }
}
