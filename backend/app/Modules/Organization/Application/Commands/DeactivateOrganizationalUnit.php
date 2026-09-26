<?php

namespace App\Modules\Organization\Application\Commands;

use App\Modules\Organization\Domain\Exceptions\StaleVersionException;
use App\Modules\Organization\Infrastructure\Persistence\Eloquent\OrganizationalUnit;

/**
 * Deactivates a unit, optimistic-concurrency guarded (spec §25). No transaction of its own (same
 * ERRATA-02 rationale as RenameOrganizationalUnit). No hard delete ever (spec §9/§34). No cascade
 * to children, and a parent may be deactivated while it still has active children — both
 * explicitly required/precedented, not invented (spec §8 D19/D20).
 */
final class DeactivateOrganizationalUnit
{
    /** @throws StaleVersionException */
    public function handle(OrganizationalUnit $unit, int $expectedVersion): OrganizationalUnit
    {
        $updated = OrganizationalUnit::query()
            ->where('id', $unit->getKey())
            ->where('version', $expectedVersion)
            ->update(['is_active' => false, 'version' => $expectedVersion + 1]);

        if ($updated === 0) {
            OrganizationalUnit::query()->where('id', $unit->getKey())->firstOrFail();

            throw new StaleVersionException;
        }

        return $unit->refresh();
    }
}
