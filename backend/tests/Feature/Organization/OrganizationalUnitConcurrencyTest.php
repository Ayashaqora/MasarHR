<?php

namespace Tests\Feature\Organization;

use PDO;
use Tests\Support\TestDatabaseGuard;

/**
 * Proves MoveOrganizationalUnit's concurrency primitive directly against two independent
 * PostgreSQL sessions (spec §13/§35 item 1, §39): the fallback the spec itself authorizes when the
 * test harness's own transaction wrapping (DatabaseTransactions — every other Organization test
 * uses it, and fixtures created inside it are not visible to a second real session) cannot drive
 * true multi-connection concurrency against fixture data. This test uses the exact lock name and
 * acquisition statement MoveOrganizationalUnit::handle() issues
 * (`select pg_advisory_xact_lock(hashtext('org.organizational_units.hierarchy'))`), so it proves
 * the real mechanism, not a stand-in.
 */
class OrganizationalUnitConcurrencyTest extends OrganizationTestCase
{
    private const LOCK_KEY = 'org.organizational_units.hierarchy';

    /** A raw PDO connection independent of Laravel's own, opened against the exact same guarded test database. */
    private function rawSession(): PDO
    {
        $config = config('database.connections.pgsql');

        $violation = TestDatabaseGuard::violation('pgsql', $config['database']);
        if ($violation !== null) {
            $this->fail($violation);
        }

        $dsn = sprintf('pgsql:host=%s;port=%s;dbname=%s', $config['host'], $config['port'], $config['database']);
        $pdo = new PDO($dsn, $config['username'], $config['password']);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        return $pdo;
    }

    public function test_the_hierarchy_advisory_lock_serializes_concurrent_move_attempts_across_sessions(): void
    {
        $acquire = "select pg_advisory_xact_lock(hashtext('".self::LOCK_KEY."'))";
        $tryAcquire = "select pg_try_advisory_xact_lock(hashtext('".self::LOCK_KEY."')) as acquired";

        $sessionA = $this->rawSession();
        $sessionB = $this->rawSession();

        // Session A: exactly what MoveOrganizationalUnit::handle() does first.
        $sessionA->beginTransaction();
        $sessionA->exec($acquire);

        // Session B must not be able to acquire the same named lock while session A holds it —
        // this is what fully serializes concurrent Move transactions against each other, so two
        // overlapping moves can never interleave their cycle checks and updates (spec §35 item 1).
        $sessionB->beginTransaction();
        $blocked = $sessionB->query($tryAcquire)->fetch(PDO::FETCH_ASSOC);
        $this->assertFalse(
            (bool) $blocked['acquired'],
            'a second session must not be able to acquire the hierarchy advisory lock while session A holds it',
        );
        $sessionB->rollBack();

        // pg_advisory_xact_lock is transaction-scoped: ending session A's transaction releases it
        // automatically, with no separate unlock call required (spec §13/§35 item 8).
        $sessionA->rollBack();

        $sessionB->beginTransaction();
        $nowFree = $sessionB->query($tryAcquire)->fetch(PDO::FETCH_ASSOC);
        $this->assertTrue(
            (bool) $nowFree['acquired'],
            'the lock must be released automatically once session A commits or rolls back',
        );
        $sessionB->rollBack();
    }
}
