<?php

namespace App\Modules\Organization\Application\Commands;

use App\Modules\Organization\Domain\Exceptions\StaleVersionException;
use App\Modules\Organization\Infrastructure\Persistence\Eloquent\OrganizationalUnit;

/**
 * Activates a unit, optimistic-concurrency guarded (spec §25). No transaction of its own (same
 * ERRATA-02 rationale as RenameOrganizationalUnit). No cascade to children — activation status is
 * this unit's own field only (spec §8 D19).
 */
final class ActivateOrganizationalUnit
{
    /** @throws StaleVersionException */
    public function handle(OrganizationalUnit $unit, int $expectedVersion): OrganizationalUnit
    {
        $updated = OrganizationalUnit::query()
            ->where('id', $unit->getKey())
            ->where('version', $expectedVersion)
            ->update(['is_active' => true, 'version' => $expectedVersion + 1]);

        if ($updated === 0) {
            OrganizationalUnit::query()->where('id', $unit->getKey())->firstOrFail();

            throw new StaleVersionException;
        }

        return $unit->refresh();
    }
}
