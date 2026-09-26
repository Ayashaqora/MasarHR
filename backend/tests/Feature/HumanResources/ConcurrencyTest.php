<?php

namespace Tests\Feature\HumanResources;

use App\Modules\Platform\Infrastructure\Persistence\Postgres\PostgresErrorClassifier as Errors;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\PostgresIntegrationTestCase;
use Tests\Support\TestDatabaseGuard;

/**
 * Real cross-session races against hr.employment_relationships (spec §14/§23): two independent
 * PostgreSQL sessions, exactly the pattern Tests\Feature\Database\TransactionAndConcurrencyTest
 * already established at the generic-mechanism level — this file proves the SAME behaviour holds
 * for S09's own specific constraints (the no-overlap EXCLUDE and the partial PERMANENT-number
 * UNIQUE index), not merely that a pre-check exists at the application layer.
 *
 * Deliberately extends PostgresIntegrationTestCase directly, NOT the HumanResourcesTestCase/
 * SecurityTestCase hierarchy: those use DatabaseTransactions, which wraps each test in a
 * savepoint rather than a real top-level transaction, so a "commit" from this test would only
 * release that savepoint, never make the row visible/lock-released to a genuinely independent
 * second session. TransactionAndConcurrencyTest avoids the same trait for the same reason; this
 * class mirrors that choice and, like it, cleans up its own committed fixture rows explicitly.
 */
class ConcurrencyTest extends PostgresIntegrationTestCase
{
    private const SECOND = 'pgsql_second_session';

    /** @var list<string> */
    private array $personIds = [];

    protected function tearDown(): void
    {
        foreach ([DB::connection(), $this->hasSecond() ? $this->second() : null] as $connection) {
            while ($connection !== null && $connection->transactionLevel() > 0) {
                $connection->rollBack();
            }
        }

        if ($this->personIds !== []) {
            DB::table('hr.employment_relationships')->whereIn('person_id', $this->personIds)->delete();
            DB::table('hr.persons')->whereIn('id', $this->personIds)->delete();
        }

        DB::purge(self::SECOND);

        parent::tearDown();
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

    /** Committed directly (not via CreatePerson) so it is visible to a genuinely independent second session. */
    private function person(): string
    {
        $id = (string) Str::uuid7();
        DB::table('hr.persons')->insert([
            'id' => $id,
            'national_id' => (string) random_int(1_000_000_000, 9_999_999_999),
            'is_terminal' => false,
            'version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->personIds[] = $id;

        return $id;
    }

    private function permanentEmploymentTypeId(): string
    {
        return (string) DB::table('ref.employment_types')->where('code', 'permanent')->value('id');
    }

    private function insertSql(): string
    {
        return <<<'SQL'
            insert into hr.employment_relationships
                (id, person_id, employment_type_id, employee_number, employee_number_scheme,
                 effective_from, end_knowledge_state, version, created_at, updated_at)
            values (?, ?, ?, ?, ?, ?, 'NOT_APPLICABLE', 1, now(), now())
            SQL;
    }

    public function test_concurrent_overlapping_relationships_for_the_same_person_are_serialised_by_the_exclusion_constraint(): void
    {
        $personId = $this->person();
        $employmentTypeId = $this->permanentEmploymentTypeId();
        $first = $this->pg();
        $second = $this->second();

        $first->beginTransaction();
        $first->insert($this->insertSql(), [
            (string) Str::uuid7(), $personId, $employmentTypeId, 'PN-RACE-1', 'PERMANENT', '2026-01-01',
        ]); // not committed yet

        $second->statement("set lock_timeout = '300ms'");
        $blocked = $this->databaseError(fn () => $second->transaction(fn () => $second->insert($this->insertSql(), [
            (string) Str::uuid7(), $personId, $employmentTypeId, 'PN-RACE-2', 'PERMANENT', '2026-06-01',
        ])));
        $this->assertTrue(Errors::isLockNotAvailable($blocked), 'the second session waits on the first (lock_timeout fired)');

        $first->commit();

        $rejected = $this->databaseError(fn () => $second->transaction(fn () => $second->insert($this->insertSql(), [
            (string) Str::uuid7(), $personId, $employmentTypeId, 'PN-RACE-2', 'PERMANENT', '2026-06-01',
        ])));
        $this->assertTrue(Errors::isExclusionViolation($rejected), 'once committed, the overlap is rejected by PostgreSQL itself, not merely delayed');

        $this->assertSame(1, (int) $this->scalar('select count(*) from hr.employment_relationships where person_id = ?', [$personId]));
    }

    public function test_concurrent_reservation_of_the_same_permanent_employee_number_for_different_people_is_rejected(): void
    {
        $personAId = $this->person();
        $personBId = $this->person();
        $employmentTypeId = $this->permanentEmploymentTypeId();
        $first = $this->pg();
        $second = $this->second();

        $first->beginTransaction();
        $first->insert($this->insertSql(), [
            (string) Str::uuid7(), $personAId, $employmentTypeId, 'PN-CONTESTED', 'PERMANENT', '2026-01-01',
        ]); // not committed yet

        $second->statement("set lock_timeout = '300ms'");
        $blocked = $this->databaseError(fn () => $second->transaction(fn () => $second->insert($this->insertSql(), [
            (string) Str::uuid7(), $personBId, $employmentTypeId, 'PN-CONTESTED', 'PERMANENT', '2026-01-01',
        ])));
        $this->assertTrue(Errors::isLockNotAvailable($blocked), 'the second session waits on the first (lock_timeout fired)');

        $first->commit();

        $rejected = $this->databaseError(fn () => $second->transaction(fn () => $second->insert($this->insertSql(), [
            (string) Str::uuid7(), $personBId, $employmentTypeId, 'PN-CONTESTED', 'PERMANENT', '2026-01-01',
        ])));
        $this->assertTrue(Errors::isUniqueViolation($rejected), 'once committed, the duplicate permanent number is rejected by PostgreSQL itself');

        $this->assertSame(1, (int) $this->scalar('select count(*) from hr.employment_relationships where employee_number = ?', ['PN-CONTESTED']));
    }

    public function test_concurrent_scoped_version_updates_leave_exactly_one_winner(): void
    {
        $personId = $this->person();
        $employmentTypeId = $this->permanentEmploymentTypeId();
        $relationshipId = (string) Str::uuid7();
        $this->pg()->insert($this->insertSql(), [$relationshipId, $personId, $employmentTypeId, 'PN-RACE-VER', 'PERMANENT', '2026-01-01']);

        $first = $this->pg();
        $second = $this->second();

        $updateSql = "update hr.employment_relationships set effective_to = ?, end_knowledge_state = 'KNOWN', ended_terminally = false, version = version + 1 where id = ? and version = 1 and end_knowledge_state != 'KNOWN'";

        $firstUpdated = $first->update($updateSql, ['2026-05-01', $relationshipId]);
        $secondUpdated = $second->update($updateSql, ['2026-06-01', $relationshipId]);

        $this->assertSame(1, $firstUpdated + $secondUpdated, 'exactly one of the two scoped updates succeeds against the same starting version');
    }
}
