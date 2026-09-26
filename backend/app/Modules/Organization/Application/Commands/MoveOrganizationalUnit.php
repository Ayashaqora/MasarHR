<?php

namespace App\Modules\Organization\Application\Commands;

use App\Modules\Organization\Domain\Exceptions\StaleVersionException;
use App\Modules\Organization\Domain\Exceptions\WouldCreateCycleException;
use App\Modules\Organization\Infrastructure\Persistence\Eloquent\OrganizationalUnit;
use Illuminate\Support\Facades\DB;

/**
 * Re-parents a unit (spec §13/§25). Two layers of concurrency protection, both required:
 *
 * 1. A single named pg_advisory_xact_lock scoped to the whole hierarchy, acquired first — it
 *    serializes every concurrent Move transaction against every other one. Transaction-scoped:
 *    released automatically at commit/rollback, and acquired inside the one transaction
 *    AuditedCommandExecutor already opens for this call — this never opens a second transaction
 *    (spec §35 item 8).
 * 2. With the lock held, a recursive-CTE walk from the proposed new parent upward through its
 *    ancestors: if the unit being moved appears in that chain (or is the proposed parent itself),
 *    the move is rejected with WouldCreateCycleException before any UPDATE runs.
 *
 * The database CHECK constraint (parent_id <> id) is the always-on backstop for the trivial
 * self-parent case regardless of this application-level check (spec §13 layer 1).
 */
final class MoveOrganizationalUnit
{
    /** @throws StaleVersionException|WouldCreateCycleException */
    public function handle(OrganizationalUnit $unit, ?string $newParentId, int $expectedVersion): OrganizationalUnit
    {
        DB::statement('select pg_advisory_xact_lock(hashtext(?))', ['org.organizational_units.hierarchy']);

        if ($newParentId !== null) {
            if ($newParentId === $unit->getKey()) {
                throw new WouldCreateCycleException;
            }

            OrganizationalUnit::query()->findOrFail($newParentId);

            $result = DB::selectOne(
                <<<'SQL'
                    WITH RECURSIVE ancestors AS (
                        SELECT id, parent_id FROM org.organizational_units WHERE id = ?
                        UNION ALL
                        SELECT u.id, u.parent_id
                        FROM org.organizational_units u
                        INNER JOIN ancestors a ON u.id = a.parent_id
                    )
                    SELECT EXISTS (SELECT 1 FROM ancestors WHERE id = ?) AS would_cycle
                    SQL,
                [$newParentId, $unit->getKey()],
            );

            if ((bool) $result->would_cycle) {
                throw new WouldCreateCycleException;
            }
        }

        $updated = OrganizationalUnit::query()
            ->where('id', $unit->getKey())
            ->where('version', $expectedVersion)
            ->update(['parent_id' => $newParentId, 'version' => $expectedVersion + 1]);

        if ($updated === 0) {
            OrganizationalUnit::query()->where('id', $unit->getKey())->firstOrFail();

            throw new StaleVersionException;
        }

        return $unit->refresh();
    }
}
