<?php

namespace App\Modules\Security\Application\Commands;

use App\Modules\Security\Domain\Exceptions\StaleVersionException;
use App\Modules\Security\Domain\PrincipalStatus;
use App\Modules\Security\Infrastructure\Persistence\Eloquent\Principal;
use Illuminate\Support\Facades\DB;

/**
 * A transition to DISABLED must be invoked through
 * App\Modules\Security\Application\Security\SecurityAdministrationGuard::run() by the caller (see
 * the Security presentation controller) so the last-security-administrator invariant is enforced.
 * This class itself performs only the state transition and optimistic-concurrency check.
 */
final class ChangePrincipalStatus
{
    /** @throws StaleVersionException */
    public function handle(Principal $principal, PrincipalStatus $newStatus, int $expectedVersion): Principal
    {
        return DB::transaction(function () use ($principal, $newStatus, $expectedVersion) {
            $updated = Principal::query()
                ->where('id', $principal->getKey())
                ->where('version', $expectedVersion)
                ->update([
                    'status' => $newStatus->value,
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
