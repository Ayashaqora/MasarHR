<?php

namespace App\Modules\Security\Application\Commands;

use App\Modules\Security\Domain\Exceptions\StaleVersionException;
use App\Modules\Security\Domain\PrincipalStatus;
use App\Modules\Security\Infrastructure\Persistence\Eloquent\Principal;

/**
 * A transition to DISABLED must be invoked through
 * App\Modules\Security\Application\Security\SecurityAdministrationGuard::protect() by the caller
 * (see the Security presentation controller) so the last-security-administrator invariant is
 * enforced. This class itself performs only the state transition and optimistic-concurrency check.
 *
 * S04 ERRATA-02 correction: this command opens no transaction of its own — see ActivateRole for
 * the full rationale (a single scoped UPDATE is already atomic on its own; wrapping it in
 * DB::transaction() only added a SAVEPOINT when invoked from inside AuditedCommandExecutor's
 * caller-owned transaction, which also owns SecurityAdministrationGuard::protect()'s advisory lock
 * and last-admin check for this path).
 */
final class ChangePrincipalStatus
{
    /** @throws StaleVersionException */
    public function handle(Principal $principal, PrincipalStatus $newStatus, int $expectedVersion): Principal
    {
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
    }
}
