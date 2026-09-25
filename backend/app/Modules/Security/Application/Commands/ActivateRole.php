<?php

namespace App\Modules\Security\Application\Commands;

use App\Modules\Security\Domain\Exceptions\StaleVersionException;
use App\Modules\Security\Infrastructure\Persistence\Eloquent\Role;

/**
 * S04 ERRATA-02 correction: this command opens no transaction of its own. Its mutation is a single
 * scoped `UPDATE ... WHERE version = expected_version`, already atomic as a standalone statement
 * (PostgreSQL wraps every standalone statement in an implicit transaction) — wrapping it in an
 * explicit DB::transaction() added nothing here but a SAVEPOINT when invoked from inside a
 * caller-owned transaction, which is exactly the nested-transaction dependency ERRATA-02 removes
 * from the audited execution path (AuditedCommandExecutor owns the one effective transaction). The
 * fallback firstOrFail() below (distinguishing a stale version from a missing role) is a plain
 * read and needs no transactional consistency with the UPDATE that already failed to match.
 */
final class ActivateRole
{
    /** @throws StaleVersionException */
    public function handle(Role $role, int $expectedVersion): Role
    {
        $updated = Role::query()
            ->where('id', $role->getKey())
            ->where('version', $expectedVersion)
            ->update(['is_active' => true, 'version' => $expectedVersion + 1]);

        if ($updated === 0) {
            Role::query()->where('id', $role->getKey())->firstOrFail();

            throw new StaleVersionException;
        }

        return $role->refresh();
    }
}
