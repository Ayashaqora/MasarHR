<?php

namespace App\Modules\Security\Application\Commands;

use App\Modules\Security\Domain\Exceptions\StaleVersionException;
use App\Modules\Security\Infrastructure\Persistence\Eloquent\Principal;
use Illuminate\Support\Facades\DB;

final class ChangePrincipalDisplayName
{
    /** @throws StaleVersionException */
    public function handle(Principal $principal, string $newDisplayName, int $expectedVersion): Principal
    {
        return DB::transaction(function () use ($principal, $newDisplayName, $expectedVersion) {
            $updated = Principal::query()
                ->where('id', $principal->getKey())
                ->where('version', $expectedVersion)
                ->update([
                    'display_name' => $newDisplayName,
                    'version' => $expectedVersion + 1,
                ]);

            if ($updated === 0) {
                Principal::query()->where('id', $principal->getKey())->firstOrFail();

                throw new StaleVersionException;
            }

            return $principal->refresh();
        });
    }
}
