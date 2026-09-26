<?php

namespace App\Modules\Security\Application\Queries;

use App\Modules\Security\Domain\EffectiveOrganizationalScope;
use App\Modules\Security\Infrastructure\Persistence\Eloquent\OrganizationalScopeGrant;
use App\Modules\Security\Infrastructure\Persistence\Eloquent\Principal;
use Illuminate\Support\Facades\DB;

/**
 * Resolves a principal's effective organizational scope (spec §11): GLOBAL short-circuits
 * immediately (a GLOBAL grant is absorbing — ADR-S08-002); otherwise, the union of every UNIT
 * grant's inclusive subtree is computed in a single WITH RECURSIVE query seeded from the whole set
 * of granted unit ids at once, reusing the exact recursive shape
 * Organization\Application\Queries\ListOrganizationalUnitDescendants already established for S07 —
 * generalized to a multi-row seed rather than duplicated. Deliberately does not filter on
 * is_active while walking the tree (spec §13): descendant membership is pure tree structure;
 * activity is instead gated once, at the authorization target, by ScopedAuthorizationChecker. Pure
 * read, no mutation.
 */
final class ResolveEffectiveOrganizationalScope
{
    public function __invoke(Principal $principal): EffectiveOrganizationalScope
    {
        $grants = OrganizationalScopeGrant::query()
            ->where('principal_id', $principal->getKey())
            ->get(['scope_kind', 'organizational_unit_id']);

        if ($grants->contains(fn (OrganizationalScopeGrant $grant) => $grant->scope_kind === 'GLOBAL')) {
            return EffectiveOrganizationalScope::global();
        }

        $seedUnitIds = $grants->pluck('organizational_unit_id')->filter()->values()->all();

        if ($seedUnitIds === []) {
            return EffectiveOrganizationalScope::units([]);
        }

        $rows = DB::select(
            <<<'SQL'
                WITH RECURSIVE scope_units AS (
                    SELECT id FROM org.organizational_units WHERE id = ANY(?::uuid[])
                    UNION ALL
                    SELECT child.id
                    FROM org.organizational_units child
                    INNER JOIN scope_units ON child.parent_id = scope_units.id
                )
                SELECT DISTINCT id FROM scope_units
                SQL,
            ['{'.implode(',', $seedUnitIds).'}'],
        );

        return EffectiveOrganizationalScope::units(array_map(fn (object $row) => $row->id, $rows));
    }
}
