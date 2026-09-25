<?php

namespace Tests\Feature\Security;

use App\Modules\Security\Application\Commands\AssignRoleToPrincipal;
use App\Modules\Security\Application\Commands\CreatePrincipal;
use App\Modules\Security\Application\Commands\CreateRole;
use App\Modules\Security\Application\Commands\GrantPermissionToRole;
use App\Modules\Security\Application\Commands\SetInitialPassword;
use App\Modules\Security\Infrastructure\Authorization\PermissionCatalog;
use App\Modules\Security\Infrastructure\Persistence\Eloquent\Permission;
use App\Modules\Security\Infrastructure\Persistence\Eloquent\Principal;
use App\Modules\Security\Infrastructure\Persistence\Eloquent\Role;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\PostgresIntegrationTestCase;

/**
 * Base class for S03 Security feature tests. Builds fixtures through the real Application
 * commands (dogfooding) rather than raw inserts, and wraps every test in a rolled-back
 * transaction (never migrate:fresh — see docs/database-persistence-foundation.md §9) on top of
 * PostgresIntegrationTestCase's one-time migration of the guarded masarhr_test database.
 */
abstract class SecurityTestCase extends PostgresIntegrationTestCase
{
    use DatabaseTransactions;

    protected const VALID_PASSWORD = 'CorrectHorseBattery9!';

    protected function uniqueUsername(string $prefix = 'user'): string
    {
        return $prefix.'_'.Str::lower(Str::random(10));
    }

    protected function createPrincipal(?string $username = null, string $password = self::VALID_PASSWORD, ?string $displayName = null): Principal
    {
        $username ??= $this->uniqueUsername();
        $displayName ??= 'Test '.$username;

        $principal = app(CreatePrincipal::class)->handle($username, $displayName);
        app(SetInitialPassword::class)->handle($principal, $password);

        return $principal->refresh();
    }

    /** @param list<string> $permissionCodes */
    protected function createRoleWithPermissions(array $permissionCodes, ?string $code = null, bool $active = true): Role
    {
        $code ??= 'role_'.Str::lower(Str::random(10));

        $role = app(CreateRole::class)->handle($code, 'دور '.$code, 'Role '.$code, null);

        if (! $active) {
            $role->forceFill(['is_active' => false])->save();
        }

        foreach ($permissionCodes as $permissionCode) {
            $permission = Permission::query()->where('code', $permissionCode)->firstOrFail();
            app(GrantPermissionToRole::class)->handle($role, $permission);
        }

        return $role->refresh();
    }

    protected function assignRole(Principal $principal, Role $role, ?Principal $assignedBy = null): void
    {
        app(AssignRoleToPrincipal::class)->handle($principal, $role, $assignedBy);
    }

    /** A principal with every baseline Security permission via a fresh active role — a full security administrator. */
    protected function createSecurityAdministrator(?string $username = null): Principal
    {
        $principal = $this->createPrincipal($username);
        $role = $this->createRoleWithPermissions(PermissionCatalog::ALL);
        $this->assignRole($principal, $role);

        return $principal;
    }
}
