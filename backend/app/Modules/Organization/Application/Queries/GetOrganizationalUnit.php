<?php

namespace App\Modules\Organization\Application\Queries;

use App\Modules\Organization\Infrastructure\Persistence\Eloquent\OrganizationalUnit;

/**
 * Plain Eloquent find (spec §14). The HTTP show() route uses Laravel's implicit route-model
 * binding directly instead of invoking this class — matching every S05 show() precedent exactly —
 * so this class exists as the query contract any other caller (non-HTTP, a future consumer) uses.
 */
final class GetOrganizationalUnit
{
    public function __invoke(string $id): OrganizationalUnit
    {
        return OrganizationalUnit::query()->findOrFail($id);
    }
}
