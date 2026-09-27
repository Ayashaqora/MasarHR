<?php

namespace Tests\Feature\Audit;

use App\Modules\Audit\Application\AuditedCommandExecutor;
use App\Modules\Audit\Domain\AuditSpec;
use App\Modules\Platform\Application\Execution\CommandContext;
use App\Modules\Platform\Domain\Actor;
use App\Modules\Platform\Domain\CorrelationId;
use App\Modules\Platform\Domain\Source;
use App\Modules\Security\Application\Security\SecurityAdministrationGuard;
use Illuminate\Support\Facades\DB;

/**
 * ERRATA-02: audited mutations must not intentionally depend on nested DB::transaction/SAVEPOINT
 * behavior. SecurityAdministrationGuard::protect() must operate inside a transaction the caller
 * already owns, without opening one of its own, while run() remains a transaction-opening
 * convenience wrapper preserved only for direct callers outside the audited execution path.
 */
class GuardExecutorTransactionTest extends AuditTestCase
{
    public function test_protect_does_not_open_a_nested_transaction_of_its_own(): void
    {
        $this->createSecurityAdministrator();
        $guard = app(SecurityAdministrationGuard::class);

        DB::transaction(function () use ($guard): void {
            $levelBefore = DB::transactionLevel();

            $levelDuringProtect = $guard->protect(fn () => DB::transactionLevel());

            $this->assertSame($levelBefore, $levelDuringProtect, 'protect() must not increase the transaction level (ERRATA-02)');
        });
    }

    public function test_run_still_opens_its_own_transaction_for_backward_compatible_direct_callers(): void
    {
        $this->createSecurityAdministrator();
        $guard = app(SecurityAdministrationGuard::class);

        $levelBefore = DB::transactionLevel();
        $levelDuringRun = $guard->run(fn () => DB::transactionLevel());

        $this->assertSame($levelBefore + 1, $levelDuringRun, 'run() remains a transaction-opening wrapper around protect()');
    }

    public function test_executor_plus_guard_protect_together_open_exactly_one_transaction(): void
    {
        $admin = $this->createSecurityAdministrator();
        $this->createSecurityAdministrator(); // a second capable administrator so the invariant passes.
        $guard = app(SecurityAdministrationGuard::class);
        $executor = app(AuditedCommandExecutor::class);

        $context = new CommandContext(Actor::human($admin->getKey()), CorrelationId::generate(), Source::Http);
        $spec = new AuditSpec('test.guard.single_transaction', 'security_principal', fn () => $admin->getKey(), fn () => [], fn () => []);

        $levelBefore = DB::transactionLevel();
        $levelDuring = $executor->run($context, $spec, fn () => $guard->protect(fn () => DB::transactionLevel()));

        $this->assertSame($levelBefore + 1, $levelDuring, 'AuditedCommandExecutor -> SecurityAdministrationGuard::protect() must share exactly one transaction');
    }
}
