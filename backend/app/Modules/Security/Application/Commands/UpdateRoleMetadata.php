<?php

namespace App\Modules\Security\Application\Commands;

use App\Modules\Security\Domain\Exceptions\StaleVersionException;
use App\Modules\Security\Infrastructure\Persistence\Eloquent\Role;
use Illuminate\Support\Facades\DB;

/** Updates only name_ar/name_en/description. code and is_system are immutable through this command. */
final class UpdateRoleMetadata
{
    /** @throws StaleVersionException */
    public function handle(Role $role, string $nameAr, string $nameEn, ?string $description, int $expectedVersion): Role
    {
        return DB::transaction(function () use ($role, $nameAr, $nameEn, $description, $expectedVersion) {
            $updated = Role::query()
                ->where('id', $role->getKey())
                ->where('version', $expectedVersion)
                ->update([
                    'name_ar' => $nameAr,
                    'name_en' => $nameEn,
                    'description' => $description,
                    'version' => $expectedVersion + 1,
                ]);

            if ($updated === 0) {
                Role::query()->where('id', $role->getKey())->firstOrFail();

                throw new StaleVersionException;
            }

            return $role->refresh();
        });
    }
}
