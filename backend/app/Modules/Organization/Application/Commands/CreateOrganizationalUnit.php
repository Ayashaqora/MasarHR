<?php

namespace App\Modules\Organization\Application\Commands;

use App\Modules\Organization\Infrastructure\Persistence\Eloquent\OrganizationalUnit;

/**
 * Creates a new organizational unit (spec §25). No advisory lock is needed — a brand-new unit
 * cannot yet be anyone's ancestor, so no cycle is possible at creation time regardless of
 * concurrent activity elsewhere in the tree (spec §13). A non-null parent_id must reference an
 * existing unit; enforced by both the database FK and this explicit findOrFail so a missing
 * parent surfaces as 404, not an opaque FK violation (mirrors RoleController::grantPermission's
 * precedent, cited by spec §25).
 */
final class CreateOrganizationalUnit
{
    public function handle(string $name, ?string $parentId): OrganizationalUnit
    {
        if ($parentId !== null) {
            OrganizationalUnit::query()->findOrFail($parentId);
        }

        $unit = new OrganizationalUnit([
            'name' => $name,
            'parent_id' => $parentId,
            'is_active' => true,
        ]);

        $unit->save();

        return $unit;
    }
}
