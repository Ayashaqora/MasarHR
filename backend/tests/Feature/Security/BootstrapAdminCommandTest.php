<?php

namespace Tests\Feature\Security;

use App\Modules\Security\Domain\SecurityAdministrationCapability;
use App\Modules\Security\Infrastructure\Authorization\EffectivePermissionsResolver;
use App\Modules\Security\Infrastructure\Persistence\Eloquent\Principal;
use App\Modules\Security\Infrastructure\Persistence\Eloquent\Role;

/** §19 / §30 of the S03 authorization: bootstrap CLI. */
class BootstrapAdminCommandTest extends SecurityTestCase
{
    public function test_bootstrap_creates_a_capable_active_administrator(): void
    {
        $this->artisan('masar:security:bootstrap-admin')
            ->expectsQuestion('Administrator username', 'firstadmin')
            ->expectsQuestion('Display name', 'First Admin')
            ->expectsQuestion('Password (input hidden)', 'ABootstrapPassphrase1!')
            ->expectsQuestion('Confirm password', 'ABootstrapPassphrase1!')
            ->assertExitCode(0);

        $principal = Principal::query()->where('username_normalized', 'firstadmin')->firstOrFail();
        $this->assertTrue($principal->isActive());

        $effective = app(EffectivePermissionsResolver::class)->resolve($principal);
        $this->assertTrue(SecurityAdministrationCapability::isCapable($effective));

        $role = Role::query()->where('code', 'SECURITY_ADMINISTRATOR')->firstOrFail();
        $this->assertTrue($role->is_system);
    }

    public function test_bootstrap_refuses_to_run_a_second_time(): void
    {
        $this->createSecurityAdministrator('existingadmin');

        // The command checks BOOT-07 before asking anything (fail fast, no wasted password entry),
        // so no question/answer exchange happens on this path — just an immediate refusal.
        $this->artisan('masar:security:bootstrap-admin')->assertExitCode(1);

        $this->assertNull(Principal::query()->where('username_normalized', 'secondadmin')->first());
    }

    public function test_bootstrap_rejects_a_password_confirmation_mismatch_without_creating_anything(): void
    {
        $this->artisan('masar:security:bootstrap-admin')
            ->expectsQuestion('Administrator username', 'mismatchadmin')
            ->expectsQuestion('Display name', 'Mismatch Admin')
            ->expectsQuestion('Password (input hidden)', 'FirstPassphrase1!')
            ->expectsQuestion('Confirm password', 'DifferentPassphrase2!')
            ->assertExitCode(1);

        $this->assertNull(Principal::query()->where('username_normalized', 'mismatchadmin')->first());
    }

    public function test_bootstrap_rejects_a_password_that_violates_policy_without_creating_anything(): void
    {
        $this->artisan('masar:security:bootstrap-admin')
            ->expectsQuestion('Administrator username', 'weakpwadmin')
            ->expectsQuestion('Display name', 'Weak Password Admin')
            ->expectsQuestion('Password (input hidden)', 'short')
            ->expectsQuestion('Confirm password', 'short')
            ->assertExitCode(1);

        $this->assertNull(Principal::query()->where('username_normalized', 'weakpwadmin')->first());
        $this->assertNull(Role::query()->where('code', 'SECURITY_ADMINISTRATOR')->first(), 'a rejected bootstrap must not leave a partially-created system role behind');
    }
}
