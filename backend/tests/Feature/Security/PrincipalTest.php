<?php

namespace Tests\Feature\Security;

use App\Modules\Security\Application\Commands\ChangePrincipalDisplayName;
use App\Modules\Security\Application\Commands\ChangePrincipalStatus;
use App\Modules\Security\Application\Commands\ChangePrincipalUsername;
use App\Modules\Security\Application\Commands\CreatePrincipal;
use App\Modules\Security\Domain\Exceptions\DuplicateUsernameException;
use App\Modules\Security\Domain\Exceptions\StaleVersionException;
use App\Modules\Security\Domain\PrincipalStatus;
use App\Modules\Security\Infrastructure\Authorization\PermissionCatalog;
use App\Modules\Security\Infrastructure\Persistence\Eloquent\Principal;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/** §30 PRINCIPALS. */
class PrincipalTest extends SecurityTestCase
{
    public function test_creating_a_principal_persists_normalized_username_and_active_status(): void
    {
        $principal = app(CreatePrincipal::class)->handle('  MixedCase.User  ', 'Mixed Case');

        $this->assertSame('  MixedCase.User  ', $principal->username);
        $this->assertSame('mixedcase.user', $principal->username_normalized);
        $this->assertSame(PrincipalStatus::Active, $principal->status);
        $this->assertSame(1, $principal->version);
    }

    public function test_a_duplicate_normalized_username_is_rejected(): void
    {
        app(CreatePrincipal::class)->handle('DupUser', 'First');

        $this->expectException(DuplicateUsernameException::class);
        app(CreatePrincipal::class)->handle('dupuser', 'Second');
    }

    public function test_admin_and_admin_lowercase_can_never_become_two_principals(): void
    {
        app(CreatePrincipal::class)->handle('Admin', 'First Admin');

        $this->expectException(DuplicateUsernameException::class);
        app(CreatePrincipal::class)->handle('admin', 'Second Admin');
    }

    public function test_a_concurrent_duplicate_username_insert_is_rejected_by_the_database_not_just_the_application(): void
    {
        app(CreatePrincipal::class)->handle('RaceUser', 'First');

        // Bypass the application command entirely: the UNIQUE constraint on username_normalized is
        // what actually prevents the race, not merely an application-level pre-check.
        $this->expectException(QueryException::class);
        DB::table('security.principals')->insert([
            'id' => (string) Str::uuid7(),
            'username' => 'raceuser',
            'username_normalized' => 'raceuser',
            'display_name' => 'Second',
            'status' => 'ACTIVE',
            'version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_username_change_updates_the_normalized_form_and_bumps_version(): void
    {
        $principal = $this->createPrincipal();

        $updated = app(ChangePrincipalUsername::class)->handle($principal, 'BrandNewName', $principal->version);

        $this->assertSame('BrandNewName', $updated->username);
        $this->assertSame('brandnewname', $updated->username_normalized);
        $this->assertSame(2, $updated->version);
    }

    public function test_display_name_change_bumps_version(): void
    {
        $principal = $this->createPrincipal();

        $updated = app(ChangePrincipalDisplayName::class)->handle($principal, 'New Display Name', $principal->version);

        $this->assertSame('New Display Name', $updated->display_name);
        $this->assertSame(2, $updated->version);
    }

    public function test_status_transitions_between_active_and_disabled(): void
    {
        $principal = $this->createPrincipal();

        $disabled = app(ChangePrincipalStatus::class)->handle($principal, PrincipalStatus::Disabled, $principal->version);
        $this->assertSame(PrincipalStatus::Disabled, $disabled->status);

        $reactivated = app(ChangePrincipalStatus::class)->handle($disabled, PrincipalStatus::Active, $disabled->version);
        $this->assertSame(PrincipalStatus::Active, $reactivated->status);
    }

    public function test_a_stale_expected_version_is_rejected_with_a_conflict(): void
    {
        $principal = $this->createPrincipal();
        $staleVersion = $principal->version;

        // The command returns $principal->refresh() on the very same instance, so $principal's own
        // ->version reflects the new value straight after this call — the stale one must be kept
        // separately, the way a second, independent request would only know the version it started
        // with.
        app(ChangePrincipalDisplayName::class)->handle($principal, 'First Update', $staleVersion);

        $this->expectException(StaleVersionException::class);
        app(ChangePrincipalDisplayName::class)->handle($principal, 'Conflicting Update', $staleVersion);
    }

    public function test_there_is_no_hard_delete_workflow(): void
    {
        // No Security command hard-deletes a principal row — the only supported lifecycle change
        // is a status transition (§9/§14: no hard-delete operational workflow). Commands are free
        // to delete *join-table* rows (e.g. a role assignment) as part of their normal behaviour;
        // only deleting the principal itself is prohibited.
        foreach (glob(app_path('Modules/Security/Application/Commands/*.php')) as $file) {
            $code = file_get_contents($file);
            $this->assertDoesNotMatchRegularExpression('/\$principal->delete\s*\(|Principal::destroy\s*\(/i', $code, "no principal delete/destroy call in $file");
        }

        // No route exposes a DELETE on a principal. 'roles' and 'organizational-scopes' are both
        // excluded deliberately: a DELETE there removes a join-table/grant row (a role assignment,
        // or — S08 — an organizational-scope grant), never the principal itself (spec §12/§20).
        $hasDeleteRoute = collect(Route::getRoutes())
            ->contains(fn ($route) => str_contains($route->uri(), 'security/principals')
                && in_array('DELETE', $route->methods(), true)
                && ! str_contains($route->uri(), 'roles')
                && ! str_contains($route->uri(), 'organizational-scopes'));
        $this->assertFalse($hasDeleteRoute, 'there must be no DELETE /security/principals/{id} route');
    }

    public function test_creating_a_principal_via_the_api_requires_permission(): void
    {
        $actor = $this->createPrincipal();
        $this->actingAs($actor, 'web');

        $this->postJson('/api/v1/security/principals', [
            'username' => $this->uniqueUsername(),
            'display_name' => 'Someone',
            'password' => self::VALID_PASSWORD,
        ])->assertStatus(403);
    }

    public function test_creating_a_principal_via_the_api_with_permission_succeeds_and_returns_201(): void
    {
        $actor = $this->createPrincipal();
        $role = $this->createRoleWithPermissions([PermissionCatalog::USERS_CREATE]);
        $this->assignRole($actor, $role);
        $this->actingAs($actor, 'web');

        $username = $this->uniqueUsername();

        $this->postJson('/api/v1/security/principals', [
            'username' => $username,
            'display_name' => 'Someone New',
            'password' => self::VALID_PASSWORD,
        ])->assertCreated()->assertJsonPath('username', $username);
    }
}
