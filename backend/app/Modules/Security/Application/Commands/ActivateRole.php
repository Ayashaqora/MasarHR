<?php

namespace App\Modules\Security\Application\Commands;

use App\Modules\Security\Domain\Exceptions\StaleVersionException;
use App\Modules\Security\Infrastructure\Persistence\Eloquent\Role;
use Illuminate\Support\Facades\DB;

final class ActivateRole
{
    /** @throws StaleVersionException */
    public function handle(Role $role, int $expectedVersion): Role
    {
        return DB::transaction(function () use ($role, $expectedVersion) {
            $updated = Role::query()
                ->where('id', $role->getKey())
                ->where('version', $expectedVersion)
                ->update(['is_active' => true, 'version' => $expectedVersion + 1]);

            if ($updated === 0) {
                Role::query()->where('id', $role->getKey())->firstOrFail();

                throw new StaleVersionException;
            }

            return $role->refresh();
        });
    }
}
