<?php

namespace App\Modules\Security\Application\Commands;

use App\Modules\Security\Domain\Exceptions\StaleVersionException;
use App\Modules\Security\Infrastructure\Persistence\Eloquent\Principal;

/**
 * S04 ERRATA-02 correction: this command opens no transaction of its own — see ActivateRole for
 * the full rationale (a single scoped UPDATE is already atomic on its own; wrapping it in
 * DB::transaction() only added a SAVEPOINT when invoked from inside AuditedCommandExecutor's
 * caller-owned transaction).
 */
final class ChangePrincipalDisplayName
{
    /** @throws StaleVersionException */
    public function handle(Principal $principal, string $newDisplayName, int $expectedVersion): Principal
    {
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
    }
}
