<?php

namespace Tests\Feature\Database;

use App\Modules\Platform\Infrastructure\Persistence\Postgres\PostgresErrorClassifier as Errors;
use App\Modules\Platform\Infrastructure\Persistence\Postgres\TemporalConstraints as Temporal;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\PostgresIntegrationTestCase;
use Tests\Support\TestDatabaseGuard;

/**
 * Transaction semantics and the PostgreSQL concurrency primitives later stages rely on.
 *
 * Cross-session behaviour needs committed data visible to two connections, so it uses one real but
 * ephemeral scratch table (public.s02_scratch_concurrency) that is dropped before and after every test.
 * It is created only on the guarded test database and is not part of the MasarHR schema.
 */
class TransactionAndConcurrencyTest extends PostgresIntegrationTestCase
{
    private const SCRATCH = 's02_scratch_concurrency';

    private const SECOND = 'pgsql_second_session';

    protected function setUp(): void
    {
        parent::setUp();

        $this->dropScratch();
        $this->pg()->statement(
            'create table '.self::SCRATCH.' ('.
            'id integer primary key, '.
            'balance integer not null, '.
            'owner_id uuid, effective_from date, effective_to date)',
        );
        $this->pg()->insert('insert into '.self::SCRATCH.' (id, balance) values (1, 100), (2, 200)');
    }

    protected function tearDown(): void
    {
        foreach ([DB::connection(), $this->hasSecond() ? $this->second() : null] as $connection) {
            while ($connection !== null && $connection->transactionLevel() > 0) {
                $connection->rollBack();
            }
        }

        $this->dropScratch();
        DB::purge(self::SECOND);

        parent::tearDown();
    }

    private function dropScratch(): void
    {
        $this->pg()->statement('drop table if exists public.'.self::SCRATCH);
    }

    private function hasSecond(): bool
    {
        return config('database.connections.'.self::SECOND) !== null;
    }

    /** An independent second PostgreSQL session against the same guarded test database. */
    private function second(): Connection
    {
        if (! $this->hasSecond()) {
            config(['database.connections.'.self::SECOND => config('database.connections.pgsql')]);
        }

        $connection = DB::connection(self::SECOND);

        $name = (string) $connection->selectOne('select current_database() as n')->n;
        if (TestDatabaseGuard::violation($connection->getDriverName(), $name) !== null) {
            throw new RuntimeException('Second session is not on the isolated test database.');
        }

        return $connection;
    }

    private function balance(Connection $connection, int $id): int
    {
        return (int) $connection->selectOne('select balance from '.self::SCRATCH.' where id = ?', [$id])->balance;
    }

    // ---- transactions -----------------------------------------------------------------------------------------

    public function test_an_exception_inside_a_transaction_rolls_everything_back_and_propagates_unchanged(): void
    {
        $failure = new RuntimeException('domain failure');

        try {
            $this->pg()->transaction(function () use ($failure): void {
                $this->pg()->update('update '.self::SCRATCH.' set balance = 0 where id = 1');
                $this->pg()->insert('insert into '.self::SCRATCH.' (id, balance) values (3, 300)');
                throw $failure;
            });
            $this->fail('the exception must propagate');
        } catch (RuntimeException $caught) {
            $this->assertSame($failure, $caught, 'the very same exception instance propagates');
        }

        $this->assertSame(100, $this->balance($this->pg(), 1));
        $this->assertSame(0, (int) $this->scalar('select count(*) from '.self::SCRATCH.' where id = 3'));
        $this->assertSame(0, $this->pg()->transactionLevel());
    }

    public function test_a_successful_transaction_commits_all_of_its_changes_atomically(): void
    {
        $this->pg()->transaction(function (): void {
            $this->pg()->update('update '.self::SCRATCH.' set balance = balance - 30 where id = 1');
            $this->pg()->update('update '.self::SCRATCH.' set balance = balance + 30 where id = 2');
        });

        $this->assertSame(70, $this->balance($this->pg(), 1));
        $this->assertSame(230, $this->balance($this->pg(), 2));
        $this->assertSame(70, $this->balance($this->second(), 1), 'committed data is visible to another session');
    }

    public function test_uncommitted_changes_are_invisible_to_other_sessions_until_commit(): void
    {
        $this->pg()->beginTransaction();
        $this->pg()->update('update '.self::SCRATCH.' set balance = 1 where id = 1');

        $this->assertSame(100, $this->balance($this->second(), 1));

        $this->pg()->commit();

        $this->assertSame(1, $this->balance($this->second(), 1));
    }

    public function test_a_failed_nested_transaction_rolls_back_to_its_savepoint_only(): void
    {
        $this->pg()->transaction(function (): void {
            $this->pg()->update('update '.self::SCRATCH.' set balance = 1 where id = 1');

            try {
                $this->pg()->transaction(function (): void {
                    $this->pg()->update('update '.self::SCRATCH.' set balance = 2 where id = 2');
                    throw new RuntimeException('inner');
                });
            } catch (RuntimeException) {
                // handled: only the inner unit of work is undone
            }
        });

        $this->assertSame(1, $this->balance($this->pg(), 1));
        $this->assertSame(200, $this->balance($this->pg(), 2));
    }

    public function test_a_command_that_fails_a_database_constraint_leaves_no_partial_write(): void
    {
        $error = $this->databaseError(fn () => $this->pg()->transaction(function (): void {
            $this->pg()->update('update '.self::SCRATCH.' set balance = 999 where id = 1');
            $this->pg()->insert('insert into '.self::SCRATCH.' (id, balance) values (2, 5)'); // duplicate key
        }));

        $this->assertTrue(Errors::isUniqueViolation($error));
        $this->assertSame(100, $this->balance($this->pg(), 1));
    }

    public function test_a_non_concurrency_failure_is_never_retried_even_when_attempts_are_allowed(): void
    {
        $runs = 0;

        try {
            $this->pg()->transaction(function () use (&$runs): void {
                $runs++;
                throw new RuntimeException('not a concurrency error');
            }, 3);
        } catch (RuntimeException) {
        }

        $this->assertSame(1, $runs);
    }

    // ---- concurrency primitives -------------------------------------------------------------------------------

    public function test_unique_constraints_reject_duplicates(): void
    {
        $error = $this->databaseError(fn () => $this->pg()->insert('insert into '.self::SCRATCH.' (id, balance) values (1, 5)'));

        $this->assertTrue(Errors::isUniqueViolation($error));
    }

    public function test_row_level_locks_block_other_sessions_and_nowait_fails_fast(): void
    {
        $first = $this->pg();
        $second = $this->second();

        $first->beginTransaction();
        $first->selectOne('select id from '.self::SCRATCH.' where id = 1 for update');

        $error = $this->databaseError(fn () => $second->transaction(
            fn () => $second->selectOne('select id from '.self::SCRATCH.' where id = 1 for update nowait'),
        ));
        $this->assertTrue(Errors::isLockNotAvailable($error));
        $this->assertFalse(Errors::isRetryable($error), 'lock-not-available is not automatically retryable');

        $skipped = $second->select('select id from '.self::SCRATCH.' where id in (1, 2) for update skip locked');
        $this->assertSame([2], array_map(fn ($row) => (int) $row->id, $skipped), 'SKIP LOCKED passes over the locked row');
        $second->rollBack();

        $first->commit();

        $this->assertNotNull($second->transaction(fn () => $second->selectOne('select id from '.self::SCRATCH.' where id = 1 for update nowait')));
    }

    public function test_a_concurrent_update_under_repeatable_read_is_a_retryable_serialization_failure(): void
    {
        $first = $this->pg();
        $second = $this->second();

        $first->beginTransaction();
        $first->statement('set transaction isolation level repeatable read');
        $this->assertSame('repeatable read', $first->selectOne('show transaction_isolation')->transaction_isolation);
        $this->assertSame(100, $this->balance($first, 1)); // establishes the snapshot

        $second->update('update '.self::SCRATCH.' set balance = 555 where id = 1'); // commits immediately

        $error = $this->databaseError(fn () => $first->transaction(
            fn () => $first->update('update '.self::SCRATCH.' set balance = balance + 1 where id = 1'),
        ));

        $this->assertTrue(Errors::isSerializationFailure($error), 'got '.(Errors::sqlState($error) ?? 'none'));
        $this->assertTrue(Errors::isRetryable($error));

        $first->rollBack();

        // The documented retry: the WHOLE unit of work is re-run in a fresh transaction.
        $first->transaction(fn () => $first->update('update '.self::SCRATCH.' set balance = balance + 1 where id = 1'));
        $this->assertSame(556, $this->balance($first, 1));
    }

    public function test_serializable_isolation_can_be_requested_per_transaction(): void
    {
        $this->pg()->beginTransaction();
        $this->pg()->statement('set transaction isolation level serializable');

        $this->assertSame('serializable', $this->pg()->selectOne('show transaction_isolation')->transaction_isolation);

        $this->pg()->rollBack();
        $this->assertSame('read committed', $this->scalar('show transaction_isolation'));
    }

    public function test_an_exclusion_constraint_serialises_concurrent_overlapping_inserts_across_sessions(): void
    {
        $this->pg()->statement(Temporal::noOverlapConstraintSql(self::SCRATCH, self::SCRATCH.'_no_overlap', ['owner_id']));
        $owner = '0190f3a0-0000-7000-8000-000000000001';
        $first = $this->pg();
        $second = $this->second();
        $insert = 'insert into '.self::SCRATCH.' (id, balance, owner_id, effective_from, effective_to) values (?, 0, ?, ?, ?)';

        $first->beginTransaction();
        $first->insert($insert, [10, $owner, '2026-01-01', '2026-02-01']); // not committed yet

        $second->statement("set lock_timeout = '300ms'");
        $blocked = $this->databaseError(fn () => $second->transaction(
            fn () => $second->insert($insert, [11, $owner, '2026-01-15', '2026-02-15']),
        ));
        $this->assertTrue(Errors::isLockNotAvailable($blocked), 'the second session waits on the first (lock_timeout fired)');

        $first->commit();

        $rejected = $this->databaseError(fn () => $second->transaction(
            fn () => $second->insert($insert, [11, $owner, '2026-01-15', '2026-02-15']),
        ));
        $this->assertTrue(Errors::isExclusionViolation($rejected), 'once committed, the overlap is rejected by PostgreSQL itself');

        $second->transaction(fn () => $second->insert($insert, [12, $owner, '2026-02-01', '2026-03-01'])); // adjacent is fine
        $this->assertSame(2, (int) $this->scalar('select count(*) from '.self::SCRATCH.' where owner_id = ?', [$owner]));
    }
}
