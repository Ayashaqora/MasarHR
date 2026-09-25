<?php

namespace Tests\Feature\Audit;

use App\Modules\Platform\Infrastructure\Persistence\Postgres\PostgresErrorClassifier as Errors;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * S04 AUD-05/AUD-06: the audit_entries_actor_check CHECK constraint enforces, at the database
 * level, that HUMAN always carries a real Principal id and no label, and SYSTEM never carries a
 * Principal id and always carries a label.
 */
class AuditEntryActorConstraintTest extends AuditTestCase
{
    /** @return array<string, mixed> a base valid row, overridden per test. */
    private function baseRow(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'occurred_at' => now(),
            'category' => 'SECURITY_EVENT',
            'action' => 'test.actor_constraint.probe',
            'source' => 'CLI',
            'correlation_id' => (string) Str::uuid7(),
            'target_type' => 'security_principal',
            'target_id' => null,
            'outcome' => 'SUCCEEDED',
            'changes' => null,
            'metadata' => null,
        ];
    }

    public function test_human_with_a_principal_id_and_no_label_is_accepted(): void
    {
        $admin = $this->createSecurityAdministrator();

        DB::table('audit.audit_entries')->insert(array_merge($this->baseRow(), [
            'actor_type' => 'HUMAN',
            'actor_principal_id' => $admin->getKey(),
            'actor_label' => null,
        ]));

        $this->assertSame(1, DB::table('audit.audit_entries')->where('actor_principal_id', $admin->getKey())->count());
    }

    public function test_system_with_a_label_and_no_principal_id_is_accepted(): void
    {
        DB::table('audit.audit_entries')->insert(array_merge($this->baseRow(), [
            'actor_type' => 'SYSTEM',
            'actor_principal_id' => null,
            'actor_label' => 'TEST_PROBE',
        ]));

        $this->assertSame(1, DB::table('audit.audit_entries')->where('actor_label', 'TEST_PROBE')->count());
    }

    public function test_human_with_a_label_instead_of_a_principal_id_is_rejected(): void
    {
        $error = $this->databaseError(fn () => DB::table('audit.audit_entries')->insert(array_merge($this->baseRow(), [
            'actor_type' => 'HUMAN',
            'actor_principal_id' => null,
            'actor_label' => 'SHOULD_NOT_BE_ALLOWED',
        ])));

        $this->assertTrue(Errors::isCheckViolation($error));
    }

    public function test_human_with_both_a_principal_id_and_a_label_is_rejected(): void
    {
        $admin = $this->createSecurityAdministrator();

        $error = $this->databaseError(fn () => DB::table('audit.audit_entries')->insert(array_merge($this->baseRow(), [
            'actor_type' => 'HUMAN',
            'actor_principal_id' => $admin->getKey(),
            'actor_label' => 'SHOULD_NOT_BE_ALLOWED',
        ])));

        $this->assertTrue(Errors::isCheckViolation($error));
    }

    public function test_system_with_a_principal_id_instead_of_a_label_is_rejected(): void
    {
        $admin = $this->createSecurityAdministrator();

        $error = $this->databaseError(fn () => DB::table('audit.audit_entries')->insert(array_merge($this->baseRow(), [
            'actor_type' => 'SYSTEM',
            'actor_principal_id' => $admin->getKey(),
            'actor_label' => null,
        ])));

        $this->assertTrue(Errors::isCheckViolation($error));
    }

    public function test_system_with_neither_a_principal_id_nor_a_label_is_rejected(): void
    {
        $error = $this->databaseError(fn () => DB::table('audit.audit_entries')->insert(array_merge($this->baseRow(), [
            'actor_type' => 'SYSTEM',
            'actor_principal_id' => null,
            'actor_label' => null,
        ])));

        $this->assertTrue(Errors::isCheckViolation($error));
    }

    public function test_an_unknown_actor_type_is_rejected(): void
    {
        $error = $this->databaseError(fn () => DB::table('audit.audit_entries')->insert(array_merge($this->baseRow(), [
            'actor_type' => 'ROBOT',
            'actor_principal_id' => null,
            'actor_label' => 'X',
        ])));

        $this->assertTrue(Errors::isCheckViolation($error));
    }
}
