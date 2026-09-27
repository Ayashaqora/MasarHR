<?php

namespace Tests\Feature\Audit;

use App\Modules\Audit\Application\AuditSecurityEventRecorder;
use App\Modules\Audit\Domain\Outcome;
use App\Modules\Platform\Application\Execution\CommandContext;
use App\Modules\Platform\Domain\Actor;
use App\Modules\Platform\Domain\CorrelationId;
use App\Modules\Platform\Domain\Source;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * S04 §14/D10/AUD-16: AuditSecurityEventRecorder persists a SECURITY_EVENT independently of any
 * mutation transaction boundary — including one that has already rolled back — and never lets a
 * secondary persistence failure mask, replace, or retroactively affect the original decision.
 */
class SecurityEventRecorderTest extends AuditTestCase
{
    public function test_records_a_rejection_after_the_mutations_own_transaction_has_already_rolled_back(): void
    {
        $admin = $this->createSecurityAdministrator();
        $recorder = app(AuditSecurityEventRecorder::class);
        $context = new CommandContext(Actor::human($admin->getKey()), CorrelationId::generate(), Source::Http);

        try {
            DB::transaction(function (): void {
                throw new RuntimeException('simulated mutation rejection');
            });
        } catch (RuntimeException) {
            // The outer transaction has now fully rolled back — this is the point D10 requires the
            // SECURITY_EVENT to be recorded from.
            $recorder->record($context, 'test.security_event.after_rollback', 'security_principal', $admin->getKey(), Outcome::Rejected);
        }

        $entry = $this->latestAuditEntryFor('test.security_event.after_rollback');
        $this->assertNotNull($entry, 'the SECURITY_EVENT must persist even though the mutation transaction it describes rolled back');
        $this->assertSame(Outcome::Rejected, $entry->outcome);
    }

    public function test_a_failure_to_persist_is_swallowed_and_never_rethrown(): void
    {
        $recorder = app(AuditSecurityEventRecorder::class);
        $context = new CommandContext(Actor::system('TEST_PROBE'), CorrelationId::generate(), Source::Http);
        $countBefore = $this->auditEntriesCount();

        // A denylisted metadata key makes AuditAppendService::appendSecurityEvent() throw
        // internally; the recorder must swallow that failure (AUD-16) rather than let it propagate
        // and mask/replace the original security decision its caller already produced.
        $recorder->record($context, 'test.security_event.swallowed_failure', 'security_principal', null, Outcome::Rejected, ['password' => 'x']);

        $this->assertSame($countBefore, $this->auditEntriesCount(), 'the failed write itself persists nothing');
    }

    public function test_records_a_success_outcome_correctly(): void
    {
        $admin = $this->createSecurityAdministrator();
        $recorder = app(AuditSecurityEventRecorder::class);
        $context = new CommandContext(Actor::human($admin->getKey()), CorrelationId::generate(), Source::Http);

        $recorder->record($context, 'test.security_event.success', 'security_principal', $admin->getKey(), Outcome::Succeeded);

        $entry = $this->latestAuditEntryFor('test.security_event.success');
        $this->assertNotNull($entry);
        $this->assertSame(Outcome::Succeeded, $entry->outcome);
    }
}
