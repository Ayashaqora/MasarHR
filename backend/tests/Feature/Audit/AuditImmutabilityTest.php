<?php

namespace Tests\Feature\Audit;

use App\Modules\Audit\Infrastructure\Persistence\Eloquent\AuditEntry;
use App\Modules\Platform\Infrastructure\Persistence\Postgres\PostgresErrorClassifier as Errors;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * S04 §12/D6: audit.audit_entries is append-only at the database level, independent of application
 * discipline — the audit_entries_immutable trigger rejects UPDATE/DELETE with SQLSTATE 'MA001'.
 */
class AuditImmutabilityTest extends AuditTestCase
{
    private function insertProbeRow(): string
    {
        $id = (string) Str::uuid7();

        DB::table('audit.audit_entries')->insert([
            'id' => $id,
            'occurred_at' => now(),
            'category' => 'SECURITY_EVENT',
            'action' => 'test.immutability.probe',
            'actor_type' => 'SYSTEM',
            'actor_principal_id' => null,
            'actor_label' => 'TEST_PROBE',
            'source' => 'CLI',
            'correlation_id' => (string) Str::uuid7(),
            'target_type' => 'security_principal',
            'target_id' => null,
            'outcome' => 'SUCCEEDED',
            'changes' => null,
            'metadata' => null,
        ]);

        return $id;
    }

    public function test_a_raw_sql_update_is_rejected_with_the_application_owned_sqlstate(): void
    {
        $id = $this->insertProbeRow();

        // Wrapped in its own DB::transaction() (a SAVEPOINT, since the DatabaseTransactions trait
        // already has the outer test transaction open) so the failure's automatic
        // ROLLBACK TO SAVEPOINT leaves the outer test transaction itself still usable for the
        // assertions below — a plain DB::statement() would otherwise leave it aborted.
        $error = $this->databaseError(fn () => DB::transaction(
            fn () => DB::statement('update audit.audit_entries set action = ? where id = ?', ['changed', $id])
        ));

        $this->assertSame('MA001', Errors::sqlState($error));
        $this->assertTrue(Errors::isAuditImmutabilityViolation($error));
        $this->assertSame('test.immutability.probe', DB::table('audit.audit_entries')->where('id', $id)->value('action'));
    }

    public function test_a_raw_sql_delete_is_rejected_with_the_application_owned_sqlstate(): void
    {
        $id = $this->insertProbeRow();

        $error = $this->databaseError(fn () => DB::transaction(
            fn () => DB::statement('delete from audit.audit_entries where id = ?', [$id])
        ));

        $this->assertSame('MA001', Errors::sqlState($error));
        $this->assertTrue(Errors::isAuditImmutabilityViolation($error));
        $this->assertSame(1, DB::table('audit.audit_entries')->where('id', $id)->count());
    }

    public function test_an_eloquent_save_on_an_already_persisted_entry_is_also_rejected(): void
    {
        $id = $this->insertProbeRow();
        $entry = AuditEntry::query()->findOrFail($id);
        $entry->action = 'changed-via-eloquent';

        $error = $this->databaseError(fn () => $entry->save());

        $this->assertTrue(Errors::isAuditImmutabilityViolation($error));
    }

    public function test_is_audit_immutability_violation_is_false_for_an_unrelated_sqlstate(): void
    {
        $error = $this->databaseError(fn () => DB::statement('select 1/0'));

        $this->assertFalse(Errors::isAuditImmutabilityViolation($error));
    }
}
