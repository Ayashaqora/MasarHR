<?php

namespace App\Modules\Security\Application\Queries;

use App\Modules\Security\Infrastructure\Persistence\Eloquent\OrganizationalScopeGrant;
use App\Modules\Security\Infrastructure\Persistence\Eloquent\Principal;
use Illuminate\Database\Eloquent\Collection;

/** Every scope grant a principal currently holds (spec §18 index route). Pure read, no mutation. */
final class ListOrganizationalScopeGrantsForPrincipal
{
    /** @return Collection<int, OrganizationalScopeGrant> */
    public function __invoke(Principal $principal): Collection
    {
        return OrganizationalScopeGrant::query()
            ->where('principal_id', $principal->getKey())
            ->orderBy('granted_at')
            ->get();
    }
}
