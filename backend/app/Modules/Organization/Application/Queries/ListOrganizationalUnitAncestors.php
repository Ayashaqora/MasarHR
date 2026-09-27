<?php

namespace App\Modules\Organization\Application\Queries;

use App\Modules\Organization\Infrastructure\Persistence\Eloquent\OrganizationalUnit;
use Illuminate\Support\Facades\DB;

/**
 * Breadcrumb: every ancestor of $unit, ordered root-first (spec §14/§23). $unit itself is not
 * included. Implemented via a WITH RECURSIVE CTE — no ltree/closure-table exists (spec §12). Pure
 * read, no mutation.
 */
final class ListOrganizationalUnitAncestors
{
    /** @return list<OrganizationalUnit> */
    public function __invoke(OrganizationalUnit $unit): array
    {
        if ($unit->parent_id === null) {
            return [];
        }

        $rows = DB::select(
            <<<'SQL'
                WITH RECURSIVE ancestors AS (
                    SELECT ou.*, 0 AS depth
                    FROM org.organizational_units ou
                    WHERE ou.id = ?
                    UNION ALL
                    SELECT parent.*, ancestors.depth + 1
                    FROM org.organizational_units parent
                    INNER JOIN ancestors ON parent.id = ancestors.parent_id
                )
                SELECT id, parent_id, name, is_active, version, created_at, updated_at
                FROM ancestors
                ORDER BY depth DESC
                SQL,
            [$unit->parent_id],
        );

        $prototype = new OrganizationalUnit;

        return array_map(
            fn (object $row) => $prototype->newFromBuilder((array) $row),
            $rows,
        );
    }
}
