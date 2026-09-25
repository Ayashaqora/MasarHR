<?php

namespace Tests\Feature\Audit;

use App\Modules\Audit\Application\AuditedCommandExecutor;
use App\Modules\Audit\Domain\AuditSpec;
use App\Modules\Platform\Application\Execution\CommandContext;
use App\Modules\Platform\Domain\Actor;
use App\Modules\Platform\Domain\CorrelationId;
use App\Modules\Platform\Domain\Source;
use App\Modules\Security\Application\Commands\ChangePrincipalDisplayName;
use App\Modules\Security\Application\Security\SecurityAdministrationGuard;
use App\Modules\Security\Domain\Exceptions\StaleVersionException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * S04 §9/§11/AUD-01/AUD-02: AuditedCommandExecutor owns the single outer transaction for an
 * audited mutation — the mutation and its MUTATION audit entry commit or roll back together.
 */
class AuditedCommandExecutorTest extends AuditTestCase
{
    private function context(string $principalId): CommandContext
    {
        return new CommandContext(Actor::human($principalId), CorrelationId::generate(), Source::Http);
    }

    public function test_a_successful_operation_commits_the_mutation_and_the_audit_entry_together(): void
    {
        $admin = $this->createSecurityAdministrator();
        $executor = app(AuditedCommandExecutor::class);
        $context = $this->context($admin->getKey());

        $spec = new AuditSpec(
            action: 'test.executor.success',
            targetType: 'security_principal',
            targetId: fn ($updated) => $updated->getKey(),
            changes: fn ($updated) => ['display_name' => $updated->display_name],
            metadata: fn () => [],
        );

        $updated = $executor->run($context, $spec, fn () => app(ChangePrincipalDisplayName::class)->handle($admin, 'Renamed Admin', $admin->version));

        $this->assertSame('Renamed Admin', $updated->display_name);
        $this->assertSame('Renamed Admin', $admin->fresh()->display_name);

        $entry = $this->latestAuditEntryFor('test.executor.success');
        $this->assertNotNull($entry);
        $this->assertSame($admin->getKey(), $entry->target_id);
        $this->assertSame(['display_name' => 'Renamed Admin'], $entry->changes);
    }

    public function test_an_exception_from_the_operation_rolls_back_the_mutation_and_writes_no_audit_entry(): void
    {
        $admin = $this->createSecurityAdministrator();
        $executor = app(AuditedCommandExecutor::class);
        $context = $this->context($admin->getKey());
        $countBefore = $this->auditEntriesCount();

        $spec = new AuditSpec(
            action: 'test.executor.stale_version',
            targetType: 'security_principal',
            targetId: fn ($updated) => $updated->getKey(),
            changes: fn () => [],
            metadata: fn () => [],
        );

        $wrongVersion = $admin->version + 1;

        try {
            $executor->run($context, $spec, fn () => app(ChangePrincipalDisplayName::class)->handle($admin, 'Should Not Persist', $wrongVersion));
            $this->fail('Expected StaleVersionException.');
        } catch (StaleVersionException) {
            // expected
        }

        $this->assertNotSame('Should Not Persist', $admin->fresh()->display_name);
        $this->assertSame($countBefore, $this->auditEntriesCount(), 'no audit entry when nothing was mutated (ERRATA-03)');
    }

    public function test_a_failure_while_appending_the_audit_entry_rolls_back_the_already_run_mutation_too(): void
    {
        $admin = $this->createSecurityAdministrator();
        $executor = app(AuditedCommandExecutor::class);
        $context = $this->context($admin->getKey());
        $countBefore = $this->auditEntriesCount();
        $originalDisplayName = $admin->display_name;

        // A denylisted key in `changes` makes AuditAppendService::appendMutation() throw AFTER the
        // operation closure has already run and mutated the row — proving the executor's single
        // outer transaction (AUD-01/AUD-02) rolls that mutation back too, not just the audit write.
        $spec = new AuditSpec(
            action: 'test.executor.audit_append_fails',
            targetType: 'security_principal',
            targetId: fn ($updated) => $updated->getKey(),
            changes: fn () => ['password' => 'must-never-be-written'],
            metadata: fn () => [],
        );

        $this->expectException(RuntimeException::class);

        try {
            $executor->run($context, $spec, fn () => app(ChangePrincipalDisplayName::class)->handle($admin, 'Should Also Roll Back', $admin->version));
        } finally {
            $this->assertSame($originalDisplayName, $admin->fresh()->display_name, 'the mutation must roll back when the audit append fails');
            $this->assertSame($countBefore, $this->auditEntriesCount());
        }
    }

    public function test_the_executor_plus_a_guarded_operation_together_open_exactly_one_transaction(): void
    {
        $admin = $this->createSecurityAdministrator();
        $this->createSecurityAdministrator(); // a second capable administrator, so the guard's invariant passes.
        $executor = app(AuditedCommandExecutor::class);
        $guard = app(SecurityAdministrationGuard::class);
        $context = $this->context($admin->getKey());

        $spec = new AuditSpec('test.executor.single_transaction', 'security_principal', fn () => $admin->getKey(), fn () => [], fn () => []);

        $levelBefore = DB::transactionLevel();
        $levelDuring = $executor->run($context, $spec, fn () => $guard->protect(fn () => DB::transactionLevel()));

        $this->assertSame($levelBefore + 1, $levelDuring, 'AuditedCommandExecutor + a guarded operation must share exactly one transaction (ERRATA-02)');
    }
}
