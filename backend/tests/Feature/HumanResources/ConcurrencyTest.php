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

    /** @var list<string> */
    private array $organizationalUnitIds = [];

    protected function tearDown(): void
    {
        foreach ([DB::connection(), $this->hasSecond() ? $this->second() : null] as $connection) {
            while ($connection !== null && $connection->transactionLevel() > 0) {
                $connection->rollBack();
            }
        }

        if ($this->personIds !== []) {
            $relationshipIds = DB::table('hr.employment_relationships')
                ->whereIn('person_id', $this->personIds)->pluck('id');
            // full_secondment_periods and organizational_placement_periods first: both carry a
            // RESTRICT FK to employment_relationships, same most-dependent-first ordering as
            // employment_status_periods below.
            DB::table('hr.full_secondment_periods')->whereIn('employment_relationship_id', $relationshipIds)->delete();
            DB::table('hr.organizational_placement_periods')->whereIn('employment_relationship_id', $relationshipIds)->delete();
            DB::table('hr.employment_status_periods')->whereIn('employment_relationship_id', $relationshipIds)->delete();
            DB::table('hr.employment_relationships')->whereIn('person_id', $this->personIds)->delete();
            DB::table('hr.persons')->whereIn('id', $this->personIds)->delete();
        }

        if ($this->organizationalUnitIds !== []) {
            DB::table('org.organizational_units')->whereIn('id', $this->organizationalUnitIds)->delete();
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

    private function statusDetailId(string $code): string
    {
        return (string) DB::table('ref.employment_status_details')->where('code', $code)->value('id');
    }

    /** Committed directly (not via CreateOrganizationalUnit) so it is visible to a genuinely independent second session. */
    private function organizationalUnitId(): string
    {
        $id = (string) Str::uuid7();
        DB::table('org.organizational_units')->insert([
            'id' => $id,
            'name' => 'Unit '.Str::random(8),
            'parent_id' => null,
            'is_active' => true,
            'version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->organizationalUnitIds[] = $id;

        return $id;
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

    /**
     * S10 spec §17's final backstop: even independent of RecordEmploymentStatusPeriod's own
     * lockForUpdate() serialization at the application layer, the EXCLUDE constraint itself makes
     * a genuine overlap for the same employment_relationship_id impossible — proved here with two
     * real, independent sessions racing a committed insert, exactly mirroring
     * test_concurrent_overlapping_relationships_for_the_same_person_are_serialised_by_the_exclusion_constraint.
     */
    public function test_concurrent_overlapping_status_periods_for_the_same_relationship_are_serialised_by_the_exclusion_constraint(): void
    {
        $personId = $this->person();
        $employmentTypeId = $this->permanentEmploymentTypeId();
        $relationshipId = (string) Str::uuid7();
        $this->pg()->insert($this->insertSql(), [$relationshipId, $personId, $employmentTypeId, 'PN-STATUS-RACE', 'PERMANENT', '2026-01-01']);

        $onDutyId = $this->statusDetailId('on_duty');
        $travelingId = $this->statusDetailId('traveling');
        $periodInsertSql = <<<'SQL'
            insert into hr.employment_status_periods
                (id, employment_relationship_id, status_detail_id, effective_from, created_at)
            values (?, ?, ?, ?, now())
            SQL;

        $first = $this->pg();
        $second = $this->second();

        $first->beginTransaction();
        $first->insert($periodInsertSql, [(string) Str::uuid7(), $relationshipId, $onDutyId, '2026-09-27']); // not committed yet

        $second->statement("set lock_timeout = '300ms'");
        $blocked = $this->databaseError(fn () => $second->transaction(fn () => $second->insert($periodInsertSql, [
            (string) Str::uuid7(), $relationshipId, $travelingId, '2026-10-01',
        ])));
        $this->assertTrue(Errors::isLockNotAvailable($blocked), 'the second session waits on the first (lock_timeout fired)');

        $first->commit();

        $rejected = $this->databaseError(fn () => $second->transaction(fn () => $second->insert($periodInsertSql, [
            (string) Str::uuid7(), $relationshipId, $travelingId, '2026-10-01',
        ])));
        $this->assertTrue(Errors::isExclusionViolation($rejected), 'once committed, the overlap is rejected by PostgreSQL itself, not merely delayed');

        $this->assertSame(1, (int) $this->scalar('select count(*) from hr.employment_status_periods where employment_relationship_id = ?', [$relationshipId]));
    }

    /**
     * S11 spec §15's final backstop, mirroring
     * test_concurrent_overlapping_status_periods_for_the_same_relationship_are_serialised_by_the_exclusion_constraint
     * exactly: even independent of RecordOrganizationalPlacementPeriod's own lockForUpdate()
     * serialization at the application layer, the EXCLUDE constraint itself makes a genuine
     * overlap for the same employment_relationship_id impossible, proved with two real,
     * independent sessions racing a committed insert.
     */
    public function test_concurrent_overlapping_placement_periods_for_the_same_relationship_are_serialised_by_the_exclusion_constraint(): void
    {
        $personId = $this->person();
        $employmentTypeId = $this->permanentEmploymentTypeId();
        $relationshipId = (string) Str::uuid7();
        $this->pg()->insert($this->insertSql(), [$relationshipId, $personId, $employmentTypeId, 'PN-PLACEMENT-RACE', 'PERMANENT', '2026-01-01']);

        $unitAId = $this->organizationalUnitId();
        $unitBId = $this->organizationalUnitId();
        $periodInsertSql = <<<'SQL'
            insert into hr.organizational_placement_periods
                (id, employment_relationship_id, organizational_unit_id, effective_from, created_at)
            values (?, ?, ?, ?, now())
            SQL;

        $first = $this->pg();
        $second = $this->second();

        $first->beginTransaction();
        $first->insert($periodInsertSql, [(string) Str::uuid7(), $relationshipId, $unitAId, '2026-09-27']); // not committed yet

        $second->statement("set lock_timeout = '300ms'");
        $blocked = $this->databaseError(fn () => $second->transaction(fn () => $second->insert($periodInsertSql, [
            (string) Str::uuid7(), $relationshipId, $unitBId, '2026-10-01',
        ])));
        $this->assertTrue(Errors::isLockNotAvailable($blocked), 'the second session waits on the first (lock_timeout fired)');

        $first->commit();

        $rejected = $this->databaseError(fn () => $second->transaction(fn () => $second->insert($periodInsertSql, [
            (string) Str::uuid7(), $relationshipId, $unitBId, '2026-10-01',
        ])));
        $this->assertTrue(Errors::isExclusionViolation($rejected), 'once committed, the overlap is rejected by PostgreSQL itself, not merely delayed');

        $this->assertSame(1, (int) $this->scalar('select count(*) from hr.organizational_placement_periods where employment_relationship_id = ?', [$relationshipId]));
    }

    /**
     * S12 spec §19's final backstop, mirroring
     * test_concurrent_overlapping_placement_periods_for_the_same_relationship_are_serialised_by_the_exclusion_constraint
     * exactly: even independent of StartFullSecondment's own lockForUpdate() serialization at the
     * application layer, the EXCLUDE constraint itself makes a genuine overlap for the same
     * employment_relationship_id impossible, proved with two real, independent sessions racing a
     * committed insert.
     */
    public function test_concurrent_overlapping_full_secondment_periods_for_the_same_relationship_are_serialised_by_the_exclusion_constraint(): void
    {
        $personId = $this->person();
        $employmentTypeId = $this->permanentEmploymentTypeId();
        $relationshipId = (string) Str::uuid7();
        $this->pg()->insert($this->insertSql(), [$relationshipId, $personId, $employmentTypeId, 'PN-SECONDMENT-RACE', 'PERMANENT', '2026-01-01']);

        $unitAId = $this->organizationalUnitId();
        $unitBId = $this->organizationalUnitId();
        $periodInsertSql = <<<'SQL'
            insert into hr.full_secondment_periods
                (id, employment_relationship_id, organizational_unit_id, effective_from, created_at)
            values (?, ?, ?, ?, now())
            SQL;

        $first = $this->pg();
        $second = $this->second();

        $first->beginTransaction();
        $first->insert($periodInsertSql, [(string) Str::uuid7(), $relationshipId, $unitAId, '2026-09-27']); // not committed yet

        $second->statement("set lock_timeout = '300ms'");
        $blocked = $this->databaseError(fn () => $second->transaction(fn () => $second->insert($periodInsertSql, [
            (string) Str::uuid7(), $relationshipId, $unitBId, '2026-10-01',
        ])));
        $this->assertTrue(Errors::isLockNotAvailable($blocked), 'the second session waits on the first (lock_timeout fired)');

        $first->commit();

        $rejected = $this->databaseError(fn () => $second->transaction(fn () => $second->insert($periodInsertSql, [
            (string) Str::uuid7(), $relationshipId, $unitBId, '2026-10-01',
        ])));
        $this->assertTrue(Errors::isExclusionViolation($rejected), 'once committed, the overlap is rejected by PostgreSQL itself, not merely delayed');

        $this->assertSame(1, (int) $this->scalar('select count(*) from hr.full_secondment_periods where employment_relationship_id = ?', [$relationshipId]));
    }

    /**
     * S12 spec §17 ("A StartFullSecondment call racing a concurrent EndFullSecondment call on the
     * same relationship: both acquire the same row lock, so they serialize") and spec §24's
     * explicit "concurrent start/start and start/end races (real two-connection races)"
     * requirement: proves, with two real independent sessions, that StartFullSecondment's and
     * EndFullSecondment's shared first step — `SELECT ... FOR UPDATE` on
     * hr.employment_relationships — genuinely serializes the two commands against each other, not
     * merely that each is individually safe in isolation. Simulates each command's own lock
     * acquisition directly (mirroring how the other tests in this file simulate
     * RecordOrganizationalPlacementPeriod's/StartFullSecondment's lock step via raw SQL against
     * two connections, rather than invoking the PHP command class itself, which runs on a single
     * connection and cannot represent two independent sessions).
     */
    public function test_concurrent_full_secondment_start_and_end_calls_for_the_same_relationship_are_serialised_by_the_relationship_row_lock(): void
    {
        $personId = $this->person();
        $employmentTypeId = $this->permanentEmploymentTypeId();
        $relationshipId = (string) Str::uuid7();
        $this->pg()->insert($this->insertSql(), [$relationshipId, $personId, $employmentTypeId, 'PN-SECONDMENT-START-END-RACE', 'PERMANENT', '2026-01-01']);

        $unitId = $this->organizationalUnitId();
        $openPeriodId = (string) Str::uuid7();

        // An already-open secondment for the "EndFullSecondment" session to close.
        $this->pg()->insert(<<<'SQL'
            insert into hr.full_secondment_periods
                (id, employment_relationship_id, organizational_unit_id, effective_from, created_at)
            values (?, ?, ?, ?, now())
            SQL, [$openPeriodId, $relationshipId, $unitId, '2026-09-27']);

        $lockSql = 'select id from hr.employment_relationships where id = ? for update';

        $first = $this->pg();
        $second = $this->second();

        // Simulates StartFullSecondment's own first statement: lock the relationship row.
        $first->beginTransaction();
        $first->select($lockSql, [$relationshipId]); // not committed yet

        $second->statement("set lock_timeout = '300ms'");
        // Simulates EndFullSecondment's own first statement, on an independent session: it must
        // wait behind the first session's lock, not merely behind a lock on the (different)
        // period row it will eventually update.
        $blocked = $this->databaseError(fn () => $second->transaction(fn () => $second->select($lockSql, [$relationshipId])));
        $this->assertTrue(Errors::isLockNotAvailable($blocked), "EndFullSecondment's relationship-row lock attempt waits on StartFullSecondment's (lock_timeout fired)");

        $first->commit();

        // Now unblocked: the second session performs EndFullSecondment's actual close, proving
        // the wait was genuine serialization, not a permanent deadlock or a false block on an
        // unrelated row.
        $second->transaction(function () use ($second, $lockSql, $relationshipId, $openPeriodId): void {
            $second->select($lockSql, [$relationshipId]);
            $second->update('update hr.full_secondment_periods set effective_to = ? where id = ?', ['2026-10-01', $openPeriodId]);
        });

        $this->assertSame('2026-10-01', (string) $this->scalar('select effective_to from hr.full_secondment_periods where id = ?', [$openPeriodId]));
    }
}
