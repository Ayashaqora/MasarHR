<?php

namespace App\Modules\Security\Application\Commands;

use App\Modules\Platform\Infrastructure\Persistence\Postgres\PostgresErrorClassifier as Errors;
use App\Modules\Security\Domain\Exceptions\DuplicatePermissionGrantException;
use App\Modules\Security\Infrastructure\Persistence\Eloquent\Permission;
use App\Modules\Security\Infrastructure\Persistence\Eloquent\Role;
use App\Modules\Security\Infrastructure\Persistence\Eloquent\RolePermission;
use Illuminate\Database\QueryException;

final class GrantPermissionToRole
{
    /** @throws DuplicatePermissionGrantException */
    public function handle(Role $role, Permission $permission): RolePermission
    {
        $grant = new RolePermission([
            'role_id' => $role->getKey(),
            'permission_id' => $permission->getKey(),
        ]);

        try {
            $grant->save();
        } catch (QueryException $e) {
            if (Errors::isUniqueViolation($e)) {
                throw new DuplicatePermissionGrantException;
            }

            throw $e;
        }

        return $grant;
    }
}
