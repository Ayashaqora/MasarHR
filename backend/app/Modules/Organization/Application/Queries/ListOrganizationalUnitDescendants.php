<?php

namespace App\Modules\Organization\Application\Queries;

use App\Modules\Organization\Infrastructure\Persistence\Eloquent\OrganizationalUnit;
use Illuminate\Support\Facades\DB;

/**
 * Subtree: every descendant of $unit (spec §14/§23). $unit itself is not included. The same
 * recursive shape used both for this public query and, independently, by
 * MoveOrganizationalUnit's cycle check (spec §13) — that command runs its own equivalent
 * ancestor-direction query rather than depend on this class, so this class carries no
 * mutation-path responsibility. Pure read, no mutation.
 */
final class ListOrganizationalUnitDescendants
{
    /** @return list<OrganizationalUnit> */
    public function __invoke(OrganizationalUnit $unit): array
    {
        $rows = DB::select(
            <<<'SQL'
                WITH RECURSIVE descendants AS (
                    SELECT ou.* FROM org.organizational_units ou WHERE ou.parent_id = ?
                    UNION ALL
                    SELECT child.*
                    FROM org.organizational_units child
                    INNER JOIN descendants ON child.parent_id = descendants.id
                )
                SELECT id, parent_id, name, is_active, version, created_at, updated_at
                FROM descendants
                SQL,
            [$unit->getKey()],
        );

        $prototype = new OrganizationalUnit;

        return array_map(
            fn (object $row) => $prototype->newFromBuilder((array) $row),
            $rows,
        );
    }
}
