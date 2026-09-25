<?php

namespace App\Modules\Security\Application\Commands;

use App\Modules\Platform\Infrastructure\Persistence\Postgres\PostgresErrorClassifier as Errors;
use App\Modules\Security\Domain\Exceptions\DuplicateRoleAssignmentException;
use App\Modules\Security\Infrastructure\Persistence\Eloquent\Principal;
use App\Modules\Security\Infrastructure\Persistence\Eloquent\PrincipalRole;
use App\Modules\Security\Infrastructure\Persistence\Eloquent\Role;
use Illuminate\Database\QueryException;

/** UNIQUE(principal_id, role_id) at the database level is what actually prevents a duplicate-assignment race. */
final class AssignRoleToPrincipal
{
    /** @throws DuplicateRoleAssignmentException */
    public function handle(Principal $principal, Role $role, ?Principal $assignedBy): PrincipalRole
    {
        $assignment = new PrincipalRole([
            'principal_id' => $principal->getKey(),
            'role_id' => $role->getKey(),
            'assigned_at' => now(),
            'assigned_by' => $assignedBy?->getKey(),
        ]);

        try {
            $assignment->save();
        } catch (QueryException $e) {
            if (Errors::isUniqueViolation($e)) {
                throw new DuplicateRoleAssignmentException;
            }

            throw $e;
        }

        return $assignment;
    }
}
