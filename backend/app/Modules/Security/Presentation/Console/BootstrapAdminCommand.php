<?php

namespace App\Modules\Security\Presentation\Console;

use App\Modules\Security\Application\Commands\AssignRoleToPrincipal;
use App\Modules\Security\Application\Commands\CreatePrincipal;
use App\Modules\Security\Application\Commands\SetInitialPassword;
use App\Modules\Security\Domain\Exceptions\DuplicateUsernameException;
use App\Modules\Security\Domain\Exceptions\InvalidPasswordException;
use App\Modules\Security\Domain\PasswordPolicy;
use App\Modules\Security\Domain\SecurityAdministrationCapability;
use App\Modules\Security\Infrastructure\Authorization\EffectivePermissionsResolver;
use App\Modules\Security\Infrastructure\Authorization\PermissionCatalog;
use App\Modules\Security\Infrastructure\Persistence\Eloquent\Permission;
use App\Modules\Security\Infrastructure\Persistence\Eloquent\Principal;
use App\Modules\Security\Infrastructure\Persistence\Eloquent\Role;
use App\Modules\Security\Infrastructure\Persistence\Eloquent\RolePermission;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Controlled CLI bootstrap for the very first security administrator (§19 of the S03
 * authorization). There is no public registration endpoint; this command is the only way the
 * system gets its first ACTIVE, security-administration-capable principal.
 *
 * BOOT-01 no default password: always prompted, never a literal in code.
 * BOOT-02 no credentials in the repository: nothing here is committed data.
 * BOOT-03 the plaintext password is read via a hidden prompt and never printed, logged, or passed
 *          as a command-line argument (so it never lands in shell history).
 * BOOT-04 one atomic transaction: role + permission grants + principal + credential + assignment.
 * BOOT-05 creates the required principal + role + permissions + assignments.
 * BOOT-06/07 idempotent: refuses (no side effects) once a security-administration-capable ACTIVE
 *          principal already exists.
 * BOOT-08 does not offer any ongoing management here — that is the Security Administration API.
 */
class BootstrapAdminCommand extends Command
{
    protected $signature = 'masar:security:bootstrap-admin';

    protected $description = 'Create the first security administrator principal (one-time bootstrap; no public registration exists).';

    private const SYSTEM_ROLE_CODE = 'SECURITY_ADMINISTRATOR';

    public function handle(EffectivePermissionsResolver $resolver): int
    {
        if ($this->aCapableAdministratorAlreadyExists($resolver)) {
            $this->components->error(
                'A principal capable of security administration already exists. Bootstrap only runs '
                .'once; manage accounts through the Security Administration API from here on (BOOT-07/BOOT-08).'
            );

            return self::FAILURE;
        }

        $username = $this->ask('Administrator username');
        $displayName = $this->ask('Display name');

        if (! is_string($username) || trim($username) === '' || ! is_string($displayName) || trim($displayName) === '') {
            $this->components->error('Username and display name are required.');

            return self::FAILURE;
        }

        $password = $this->promptForConfirmedPassword();
        if ($password === null) {
            return self::FAILURE;
        }

        try {
            $principal = DB::transaction(function () use ($username, $displayName, $password) {
                $role = $this->findOrCreateSystemRole();
                $this->grantAllBaselinePermissions($role);

                $principal = app(CreatePrincipal::class)->handle($username, $displayName);
                app(SetInitialPassword::class)->handle($principal, $password);
                app(AssignRoleToPrincipal::class)->handle($principal, $role, null);

                return $principal;
            });
        } catch (DuplicateUsernameException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        } catch (InvalidPasswordException $e) {
            $this->components->error('Password does not meet policy: '.implode(' ', $e->violations));

            return self::FAILURE;
        } finally {
            // The local variable holding the plaintext password goes out of scope here; nothing
            // above this point logs, echoes, or persists it anywhere but the hash created inside
            // SetInitialPassword.
            unset($password);
        }

        $this->components->info('Security administrator created.');
        $this->table(['id', 'username', 'display_name'], [[$principal->id, $principal->username, $principal->display_name]]);

        return self::SUCCESS;
    }

    private function aCapableAdministratorAlreadyExists(EffectivePermissionsResolver $resolver): bool
    {
        foreach (Principal::query()->where('status', 'ACTIVE')->get() as $principal) {
            if (SecurityAdministrationCapability::isCapable($resolver->resolve($principal))) {
                return true;
            }
        }

        return false;
    }

    private function promptForConfirmedPassword(): ?string
    {
        $password = $this->secret('Password (input hidden)');
        $confirmation = $this->secret('Confirm password');

        if ($password !== $confirmation) {
            $this->components->error('Passwords did not match.');

            return null;
        }

        if (! is_string($password) || ! PasswordPolicy::isValid($password)) {
            $this->components->error('Password does not meet policy: '.implode(' ', PasswordPolicy::violations((string) $password)));

            return null;
        }

        return $password;
    }

    private function findOrCreateSystemRole(): Role
    {
        $role = Role::query()->where('code', self::SYSTEM_ROLE_CODE)->first();

        if ($role !== null) {
            return $role;
        }

        return Role::query()->create([
            'code' => self::SYSTEM_ROLE_CODE,
            'name_ar' => 'مسؤول أمان النظام',
            'name_en' => 'Security Administrator',
            'description' => 'Full Security-module capability, created by the bootstrap command.',
            'is_system' => true,
            'is_active' => true,
        ]);
    }

    private function grantAllBaselinePermissions(Role $role): void
    {
        $alreadyGranted = RolePermission::query()->where('role_id', $role->getKey())
            ->pluck('permission_id')->all();

        $permissions = Permission::query()->whereIn('code', PermissionCatalog::ALL)->get();

        foreach ($permissions as $permission) {
            if (in_array($permission->getKey(), $alreadyGranted, true)) {
                continue;
            }

            RolePermission::query()->create([
                'role_id' => $role->getKey(),
                'permission_id' => $permission->getKey(),
            ]);
        }
    }
}
