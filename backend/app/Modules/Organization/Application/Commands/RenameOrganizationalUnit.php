<?php

namespace App\Modules\Organization\Application\Commands;

use App\Modules\Organization\Domain\Exceptions\StaleVersionException;
use App\Modules\Organization\Infrastructure\Persistence\Eloquent\OrganizationalUnit;

/**
 * Renames a unit, optimistic-concurrency guarded (spec §12/§25). No transaction of its own — the
 * scoped conditional UPDATE is already atomic on its own, and AuditedCommandExecutor owns the one
 * effective transaction (same ERRATA-02 rationale as the Reference module's
 * Abstract*SimpleReferenceValue bases).
 */
final class RenameOrganizationalUnit
{
    /** @throws StaleVersionException */
    public function handle(OrganizationalUnit $unit, string $name, int $expectedVersion): OrganizationalUnit
    {
        $updated = OrganizationalUnit::query()
            ->where('id', $unit->getKey())
            ->where('version', $expectedVersion)
            ->update(['name' => $name, 'version' => $expectedVersion + 1]);

        if ($updated === 0) {
            OrganizationalUnit::query()->where('id', $unit->getKey())->firstOrFail();

            throw new StaleVersionException;
        }

        return $unit->refresh();
    }
}
