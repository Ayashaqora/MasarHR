<?php

namespace App\Modules\Security\Application\Commands;

use App\Modules\Security\Infrastructure\Persistence\Eloquent\Principal;
use App\Modules\Security\Infrastructure\Persistence\Eloquent\Role;

/**
 * The caller must run this through SecurityAdministrationGuard::run() (see the Security
 * presentation controller) so the last-security-administrator invariant is enforced.
 */
final class RemoveRoleFromPrincipal
{
    public function handle(Principal $principal, Role $role): void
    {
        $principal->roleAssignments()->where('role_id', $role->getKey())->delete();
    }
}
