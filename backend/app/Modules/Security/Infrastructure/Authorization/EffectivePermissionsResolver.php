<?php

namespace App\Modules\Security\Infrastructure\Authorization;

use App\Modules\Security\Infrastructure\Persistence\Eloquent\Principal;
use Illuminate\Support\Facades\DB;

/**
 * §10 of the S03 authorization: effective permissions are the UNION of permissions belonging to
 * ACTIVE roles assigned to the principal. No permission match = DENY — an inactive role, or a
 * principal with no roles at all, simply contributes nothing; there is no separate "deny" row.
 */
final class EffectivePermissionsResolver
{
    /** @return list<string> Sorted, de-duplicated permission codes. */
    public function resolve(Principal $principal): array
    {
        $codes = DB::table('security.principal_roles as pr')
            ->join('security.roles as r', function ($join): void {
                $join->on('r.id', '=', 'pr.role_id')->where('r.is_active', '=', true);
            })
            ->join('security.role_permissions as rp', 'rp.role_id', '=', 'r.id')
            ->join('security.permissions as p', 'p.id', '=', 'rp.permission_id')
            ->where('pr.principal_id', '=', $principal->getKey())
            ->distinct()
            ->orderBy('p.code')
            ->pluck('p.code');

        return $codes->all();
    }

    public function has(Principal $principal, string $permissionCode): bool
    {
        return in_array($permissionCode, $this->resolve($principal), true);
    }
}
