<?php

namespace App\Modules\Organization\Application\Queries;

use App\Modules\Organization\Infrastructure\Persistence\Eloquent\OrganizationalUnit;
use Illuminate\Database\Eloquent\Collection;

/**
 * Every unit with no parent (spec §14/§23). Multiple simultaneous roots are allowed — no
 * uniqueness constraint forces exactly one (spec §35 item 12). Pure read, no mutation.
 */
final class ListRootOrganizationalUnits
{
    public function __invoke(): Collection
    {
        return OrganizationalUnit::query()->whereNull('parent_id')->orderBy('name')->get();
    }
}
