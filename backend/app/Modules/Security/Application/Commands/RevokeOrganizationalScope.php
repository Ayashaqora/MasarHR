<?php

namespace App\Modules\Security\Application\Commands;

use App\Modules\Security\Infrastructure\Persistence\Eloquent\OrganizationalScopeGrant;

/**
 * Revokes a single organizational-scope grant (spec §12). A real DELETE — no soft-delete flag, no
 * version check: a grant is either held or not, mirroring RemoveRoleFromPrincipal's own DELETE of
 * security.principal_roles.
 */
final class RevokeOrganizationalScope
{
    public function handle(OrganizationalScopeGrant $grant): void
    {
        $grant->delete();
    }
}
