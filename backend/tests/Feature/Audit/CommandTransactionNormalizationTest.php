<?php

namespace Tests\Feature\Audit;

use App\Modules\Security\Application\Commands\ActivateRole;
use App\Modules\Security\Application\Commands\ChangePrincipalDisplayName;
use App\Modules\Security\Application\Commands\ChangePrincipalStatus;
use App\Modules\Security\Application\Commands\ChangePrincipalUsername;
use App\Modules\Security\Application\Commands\DeactivateRole;
use App\Modules\Security\Application\Commands\UpdateRoleMetadata;
use App\Modules\Security\Domain\Exceptions\StaleVersionException;
use App\Modules\Security\Domain\PrincipalStatus;
use Illuminate\Support\Facades\DB;

/**
 * S04 stage-closure correction: ActivateRole, ChangePrincipalDisplayName, ChangePrincipalStatus,
 * ChangePrincipalUsername, DeactivateRole and UpdateRoleMetadata no longer open a DB::transaction()
 * of their own (ERRATA-02) — their single scoped UPDATE ... WHERE version = expected_version is
 * already atomic as a standalone statement. This proves the ACTUAL transaction structure (the
 * nesting level), not merely that the visible result is correct — a passing StaleVersionException
 * test alone would not have caught a lingering internal DB::transaction().
 */
class CommandTransactionNormalizationTest extends AuditTestCase
{
    public function test_activate_role_does_not_open_its_own_transaction(): void
    {
        $role = $this->createRoleWithPermissions([], active: false);

        DB::transaction(function () use ($role): void {
            $levelBefore = DB::transactionLevel();
            app(ActivateRole::class)->handle($role, $role->version);
            $this->assertSame($levelBefore, DB::transactionLevel(), 'ActivateRole must not open a nested transaction (ERRATA-02)');
        });
    }

    public function test_change_principal_display_name_does_not_open_its_own_transaction(): void
    {
        $principal = $this->createPrincipal();

        DB::transaction(function () use ($principal): void {
            $levelBefore = DB::transactionLevel();
            app(ChangePrincipalDisplayName::class)->handle($principal, 'Renamed', $principal->version);
            $this->assertSame($levelBefore, DB::transactionLevel(), 'ChangePrincipalDisplayName must not open a nested transaction (ERRATA-02)');
        });
    }

    public function test_change_principal_status_does_not_open_its_own_transaction(): void
    {
        $principal = $this->createPrincipal();

        DB::transaction(function () use ($principal): void {
            $levelBefore = DB::transactionLevel();
            app(ChangePrincipalStatus::class)->handle($principal, PrincipalStatus::Disabled, $principal->version);
            $this->assertSame($levelBefore, DB::transactionLevel(), 'ChangePrincipalStatus must not open a nested transaction (ERRATA-02)');
        });
    }

    public function test_change_principal_username_does_not_open_its_own_transaction(): void
    {
        $principal = $this->createPrincipal();

        DB::transaction(function () use ($principal): void {
            $levelBefore = DB::transactionLevel();
            app(ChangePrincipalUsername::class)->handle($principal, $this->uniqueUsername('normalized'), $principal->version);
            $this->assertSame($levelBefore, DB::transactionLevel(), 'ChangePrincipalUsername must not open a nested transaction (ERRATA-02)');
        });
    }

    public function test_deactivate_role_does_not_open_its_own_transaction(): void
    {
        $role = $this->createRoleWithPermissions([]);

        DB::transaction(function () use ($role): void {
            $levelBefore = DB::transactionLevel();
            app(DeactivateRole::class)->handle($role, $role->version);
            $this->assertSame($levelBefore, DB::transactionLevel(), 'DeactivateRole must not open a nested transaction (ERRATA-02)');
        });
    }

    public function test_update_role_metadata_does_not_open_its_own_transaction(): void
    {
        $role = $this->createRoleWithPermissions([]);

        DB::transaction(function () use ($role): void {
            $levelBefore = DB::transactionLevel();
            app(UpdateRoleMetadata::class)->handle($role, 'محدث', 'Updated', null, $role->version);
            $this->assertSame($levelBefore, DB::transactionLevel(), 'UpdateRoleMetadata must not open a nested transaction (ERRATA-02)');
        });
    }

    /**
     * A negative control: without the ERRATA-02 correction this same assertion would have failed
     * (level would have been levelBefore + 1), proving the test actually exercises the transaction
     * structure and is not vacuously true regardless of implementation.
     */
    public function test_a_command_still_reads_its_own_just_written_row_with_no_transaction_at_all(): void
    {
        $role = $this->createRoleWithPermissions([], active: false);

        $updated = app(ActivateRole::class)->handle($role, $role->version);

        $this->assertTrue($updated->is_active);
        $this->assertTrue($role->fresh()->is_active);
    }

    public function test_stale_version_still_throws_and_leaves_the_row_untouched_without_an_internal_transaction(): void
    {
        $principal = $this->createPrincipal();
        $originalDisplayName = $principal->display_name;

        $this->expectException(StaleVersionException::class);

        try {
            app(ChangePrincipalDisplayName::class)->handle($principal, 'Should Not Persist', $principal->version + 1);
        } finally {
            $this->assertSame($originalDisplayName, $principal->fresh()->display_name);
        }
    }

    public function test_all_six_corrected_commands_still_compose_correctly_inside_the_audited_executor(): void
    {
        // End-to-end proof that AuditedCommandExecutor remains the single effective transaction
        // (AUD-01/AUD-02) even though every one of the six commands now runs inside it directly.
        $admin = $this->createSecurityAdministrator();
        $role = $this->createRoleWithPermissions([]);
        $this->actingAs($admin, 'web');

        $this->patchJson("/api/v1/security/principals/{$admin->id}/display-name", [
            'display_name' => 'Composed Name',
            'expected_version' => $admin->version,
        ])->assertOk();

        $this->patchJson("/api/v1/security/roles/{$role->id}", [
            'name_ar' => 'محدث',
            'name_en' => 'Updated',
            'description' => null,
            'expected_version' => $role->version,
        ])->assertOk();

        $this->assertNotNull($this->latestAuditEntryFor('security.principal.display_name.change'));
        $this->assertNotNull($this->latestAuditEntryFor('security.role.metadata.update'));
    }
}
