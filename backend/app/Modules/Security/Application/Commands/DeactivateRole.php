<?php

namespace App\Modules\Security\Application\Commands;

use App\Modules\Security\Domain\Exceptions\StaleVersionException;
use App\Modules\Security\Infrastructure\Persistence\Eloquent\Role;

/**
 * Deactivating a role removes its permissions from every effective-permissions computation (an
 * inactive role contributes nothing). The caller must run this through
 * SecurityAdministrationGuard::protect() (see the Security presentation controller) so the
 * last-security-administrator invariant is enforced.
 *
 * S04 ERRATA-02 correction: this command opens no transaction of its own — see ActivateRole for
 * the full rationale.
 */
final class DeactivateRole
{
    /** @throws StaleVersionException */
    public function handle(Role $role, int $expectedVersion): Role
    {
        $updated = Role::query()
            ->where('id', $role->getKey())
            ->where('version', $expectedVersion)
            ->update(['is_active' => false, 'version' => $expectedVersion + 1]);

        if ($updated === 0) {
            Role::query()->where('id', $role->getKey())->firstOrFail();

            throw new StaleVersionException;
        }

        return $role->refresh();
    }
}
