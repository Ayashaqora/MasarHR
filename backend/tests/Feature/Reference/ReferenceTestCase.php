<?php

namespace Tests\Feature\Reference;

use App\Modules\Reference\Infrastructure\Authorization\ReferencePermissionCatalog;
use App\Modules\Security\Infrastructure\Persistence\Eloquent\Principal;
use Tests\Feature\Audit\AuditTestCase;

/**
 * Base class for S05 Reference feature tests. Inherits AuditTestCase's fixture and audit-read
 * helpers (createPrincipal/createRoleWithPermissions/assignRole/latestAuditEntryFor/...).
 */
abstract class ReferenceTestCase extends AuditTestCase
{
    /** A principal with both reference.view and reference.manage via a fresh active role. */
    protected function actingAsReferenceManager(): Principal
    {
        $principal = $this->createPrincipal();
        $role = $this->createRoleWithPermissions(ReferencePermissionCatalog::ALL);
        $this->assignRole($principal, $role);
        $this->actingAs($principal, 'web');

        return $principal;
    }

    /** A principal with reference.view only — cannot mutate. */
    protected function actingAsReferenceViewer(): Principal
    {
        $principal = $this->createPrincipal();
        $role = $this->createRoleWithPermissions([ReferencePermissionCatalog::REFERENCE_VIEW]);
        $this->assignRole($principal, $role);
        $this->actingAs($principal, 'web');

        return $principal;
    }
}
