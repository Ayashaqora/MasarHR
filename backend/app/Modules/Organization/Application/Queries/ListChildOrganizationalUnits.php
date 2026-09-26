<?php

namespace App\Modules\Organization\Application\Queries;

use App\Modules\Organization\Infrastructure\Persistence\Eloquent\OrganizationalUnit;
use Illuminate\Database\Eloquent\Collection;

/** Direct children of $unit only — not the whole subtree (spec §14/§23). Pure read, no mutation. */
final class ListChildOrganizationalUnits
{
    public function __invoke(OrganizationalUnit $unit): Collection
    {
        return OrganizationalUnit::query()->where('parent_id', $unit->getKey())->orderBy('name')->get();
    }
}
