<?php

namespace App\Modules\Security\Application\Commands;

use App\Modules\Security\Infrastructure\Persistence\Eloquent\Permission;
use App\Modules\Security\Infrastructure\Persistence\Eloquent\Role;

/**
 * The caller must run this through SecurityAdministrationGuard::run() (see the Security
 * presentation controller) so the last-security-administrator invariant is enforced.
 */
final class RevokePermissionFromRole
{
    public function handle(Role $role, Permission $permission): void
    {
        $role->rolePermissions()->where('permission_id', $permission->getKey())->delete();
    }
}
