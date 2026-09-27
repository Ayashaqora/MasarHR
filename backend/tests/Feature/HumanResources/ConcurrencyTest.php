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

    /**
     * S14 spec §17/§24 ("no new lock ordering is introduced" / "real PostgreSQL concurrency
     * tests"), mirroring
     * test_concurrent_full_secondment_start_and_end_calls_for_the_same_relationship_are_serialised_by_the_relationship_row_lock
     * exactly: TransferEmployee's own first statement (spec §14) is the identical
     * `SELECT ... FOR UPDATE` lock on hr.employment_relationships that
     * RecordOrganizationalPlacementPeriod/StartFullSecondment/EndFullSecondment already take — this
     * proves two independent, genuinely concurrent Transfer attempts against the SAME relationship
     * are serialised by that shared lock, not merely individually safe in isolation. Simulates each
     * TransferEmployee call's own lock-then-close-open-period-then-open-new-period sequence
     * directly via raw SQL against two connections (the PHP command class runs on a single
     * connection and cannot represent two independent sessions), exactly as the other tests in this
     * file simulate S11's/S12's own commands. Once genuinely serialised, the second (later-committed)
     * transfer's close-open-period step must see the first transfer's own newly-opened period as
     * "the" open period — proving the lock, not merely the EXCLUDE constraint, is what prevents two
     * simultaneously open placement periods for the same relationship.
     */
    public function test_concurrent_transfer_calls_for_the_same_relationship_are_serialised_by_the_relationship_row_lock(): void
    {
        $personId = $this->person();
        $employmentTypeId = $this->permanentEmploymentTypeId();
        $relationshipId = (string) Str::uuid7();
        $this->pg()->insert($this->insertSql(), [$relationshipId, $personId, $employmentTypeId, 'PN-TRANSFER-RACE', 'PERMANENT', '2026-01-01']);

        $unitAId = $this->organizationalUnitId();
        $unitBId = $this->organizationalUnitId();
        $unitCId = $this->organizationalUnitId();

        // The relationship's original placement (S11) — the "source" unit both racing transfers
        // start from.
        $this->pg()->insert(<<<'SQL'
            insert into hr.organizational_placement_periods
                (id, employment_relationship_id, organizational_unit_id, effective_from, created_at)
            values (?, ?, ?, ?, now())
            SQL, [(string) Str::uuid7(), $relationshipId, $unitAId, '2026-01-01']);

        $lockSql = 'select id from hr.employment_relationships where id = ? for update';
        $openPeriodSql = 'select id, organizational_unit_id from hr.organizational_placement_periods where employment_relationship_id = ? and effective_to is null';
        $closePeriodSql = 'update hr.organizational_placement_periods set effective_to = ? where id = ?';
        $openNewPeriodSql = <<<'SQL'
            insert into hr.organizational_placement_periods
                (id, employment_relationship_id, organizational_unit_id, effective_from, created_at)
            values (?, ?, ?, ?, now())
            SQL;

        $first = $this->pg();
        $second = $this->second();

        // Simulates TransferEmployee's own first statement (session 1, transferring A -> B).
        $first->beginTransaction();
        $first->select($lockSql, [$relationshipId]); // not committed yet

        $second->statement("set lock_timeout = '300ms'");
        // Simulates a second, independent TransferEmployee call (transferring the same
        // relationship to C) racing the first: it must wait behind the first session's lock, not
        // merely behind a lock on the (different) placement-period row it will eventually update.
        $blocked = $this->databaseError(fn () => $second->transaction(fn () => $second->select($lockSql, [$relationshipId])));
        $this->assertTrue(Errors::isLockNotAvailable($blocked), "the second concurrent TransferEmployee call's relationship-row lock attempt waits on the first's (lock_timeout fired)");

        // First session completes its transfer (A -> B) and commits.
        $openForFirst = $first->selectOne($openPeriodSql, [$relationshipId]);
        $this->assertSame($unitAId, (string) $openForFirst->organizational_unit_id, 'fixture assumption: A is the open period before either transfer runs');
        $first->update($closePeriodSql, ['2026-06-01', $openForFirst->id]);
        $first->insert($openNewPeriodSql, [(string) Str::uuid7(), $relationshipId, $unitBId, '2026-06-01']);
        $first->commit();

        // Now unblocked: the second session performs its own real transfer (B -> C), proving the
        // wait was genuine serialization, not a permanent deadlock or a false block on an unrelated
        // row — and that it correctly observes the FIRST transfer's own newly-opened B period as
        // "the" open period to close, not a stale pre-race snapshot of A.
        $second->transaction(function () use ($second, $lockSql, $relationshipId, $unitCId, $openPeriodSql, $closePeriodSql, $openNewPeriodSql, $unitBId): void {
            $second->select($lockSql, [$relationshipId]);
            $openForSecond = $second->selectOne($openPeriodSql, [$relationshipId]);
            $this->assertSame($unitBId, (string) $openForSecond->organizational_unit_id, 'the second, now-unblocked transfer must see the first transfer\'s own committed B period as open, not a stale A snapshot');
            $second->update($closePeriodSql, ['2026-07-01', $openForSecond->id]);
            $second->insert($openNewPeriodSql, [(string) Str::uuid7(), $relationshipId, $unitCId, '2026-07-01']);
        });

        $openPeriods = DB::table('hr.organizational_placement_periods')
            ->where('employment_relationship_id', $relationshipId)->whereNull('effective_to')->get();
        $this->assertCount(1, $openPeriods, 'exactly one open placement period survives two serialised, racing transfers');
        $this->assertSame($unitCId, (string) $openPeriods->first()->organizational_unit_id);

        $this->assertSame(3, (int) $this->scalar('select count(*) from hr.organizational_placement_periods where employment_relationship_id = ?', [$relationshipId]));
    }

    // ---------------------------------------------------------------------
    // S15 — Employment Status Lifecycle Consequences (spec §15/§21)
    // ---------------------------------------------------------------------

    /**
     * RecordEmploymentStatusPeriod's own first statement (SELECT ... FOR UPDATE on the
     * relationship) racing the direct EndEmploymentRelationship route's own first statement (the
     * scoped UPDATE, which acquires the same row's implicit write lock) — proves the two are
     * genuinely serialised, not merely individually safe.
     */
    public function test_concurrent_status_recording_and_direct_employment_end_for_the_same_relationship_are_serialised_by_the_relationship_row_lock(): void
    {
        $personId = $this->person();
        $employmentTypeId = $this->permanentEmploymentTypeId();
        $relationshipId = (string) Str::uuid7();
        $this->pg()->insert($this->insertSql(), [$relationshipId, $personId, $employmentTypeId, 'PN-STATUS-END-RACE', 'PERMANENT', '2026-01-01']);

        $lockSql = 'select id from hr.employment_relationships where id = ? for update';
        $endSql = "update hr.employment_relationships set effective_to = ?, end_knowledge_state = 'KNOWN', version = 2 where id = ? and version = 1 and end_knowledge_state != 'KNOWN'";

        $first = $this->pg();
        $second = $this->second();

        // Simulates RecordEmploymentStatusPeriod's own first statement (session 1).
        $first->beginTransaction();
        $first->select($lockSql, [$relationshipId]); // not committed yet

        $second->statement("set lock_timeout = '300ms'");
        // Simulates the direct /end route's EndEmploymentRelationship call (session 2): its scoped
        // UPDATE must wait behind session 1's lock, not merely behind an unrelated row.
        $blocked = $this->databaseError(fn () => $second->transaction(fn () => $second->update($endSql, ['2026-06-01', $relationshipId])));
        $this->assertTrue(Errors::isLockNotAvailable($blocked), "the direct end route's scoped UPDATE waits on RecordEmploymentStatusPeriod's lock (lock_timeout fired)");

        $first->commit();

        $second->transaction(fn () => $second->update($endSql, ['2026-06-01', $relationshipId]));

        $this->assertSame('KNOWN', (string) $this->scalar('select end_knowledge_state from hr.employment_relationships where id = ?', [$relationshipId]));
    }

    /**
     * ADR-S15-001 §15's explicit race list includes Status vs StartFullSecondment even though S15
     * adds no new code on this specific pairing — both already take the identical
     * SELECT ... FOR UPDATE lock as their own first statement, so this proves that generic
     * argument holds for this specific pairing too, rather than merely assuming it by analogy.
     */
    public function test_concurrent_status_recording_and_full_secondment_start_for_the_same_relationship_are_serialised_by_the_relationship_row_lock(): void
    {
        $personId = $this->person();
        $employmentTypeId = $this->permanentEmploymentTypeId();
        $relationshipId = (string) Str::uuid7();
        $this->pg()->insert($this->insertSql(), [$relationshipId, $personId, $employmentTypeId, 'PN-STATUS-START-SECONDMENT-RACE', 'PERMANENT', '2026-01-01']);
        $unitId = $this->organizationalUnitId();

        $lockSql = 'select id from hr.employment_relationships where id = ? for update';
        $startSql = <<<'SQL'
            insert into hr.full_secondment_periods
                (id, employment_relationship_id, organizational_unit_id, effective_from, created_at)
            values (?, ?, ?, ?, now())
            SQL;

        $first = $this->pg();
        $second = $this->second();

        $first->beginTransaction();
        $first->select($lockSql, [$relationshipId]); // simulates RecordEmploymentStatusPeriod's lock, not committed yet

        $second->statement("set lock_timeout = '300ms'");
        $blocked = $this->databaseError(fn () => $second->transaction(fn () => $second->select($lockSql, [$relationshipId])));
        $this->assertTrue(Errors::isLockNotAvailable($blocked), "StartFullSecondment's own lock attempt waits on RecordEmploymentStatusPeriod's (lock_timeout fired)");

        $first->commit();

        $second->transaction(function () use ($second, $lockSql, $startSql, $relationshipId, $unitId): void {
            $second->select($lockSql, [$relationshipId]);
            $second->insert($startSql, [(string) Str::uuid7(), $relationshipId, $unitId, '2026-06-01']);
        });

        $this->assertSame(1, (int) $this->scalar('select count(*) from hr.full_secondment_periods where employment_relationship_id = ?', [$relationshipId]));
    }

    /** Same reasoning as the StartFullSecondment pairing above, for EndFullSecondment. */
    public function test_concurrent_status_recording_and_full_secondment_end_for_the_same_relationship_are_serialised_by_the_relationship_row_lock(): void
    {
        $personId = $this->person();
        $employmentTypeId = $this->permanentEmploymentTypeId();
        $relationshipId = (string) Str::uuid7();
        $this->pg()->insert($this->insertSql(), [$relationshipId, $personId, $employmentTypeId, 'PN-STATUS-END-SECONDMENT-RACE', 'PERMANENT', '2026-01-01']);
        $unitId = $this->organizationalUnitId();
        $openPeriodId = (string) Str::uuid7();
        $this->pg()->insert(<<<'SQL'
            insert into hr.full_secondment_periods
                (id, employment_relationship_id, organizational_unit_id, effective_from, created_at)
            values (?, ?, ?, ?, now())
            SQL, [$openPeriodId, $relationshipId, $unitId, '2026-02-01']);

        $lockSql = 'select id from hr.employment_relationships where id = ? for update';

        $first = $this->pg();
        $second = $this->second();

        $first->beginTransaction();
        $first->select($lockSql, [$relationshipId]); // simulates RecordEmploymentStatusPeriod's lock, not committed yet

        $second->statement("set lock_timeout = '300ms'");
        $blocked = $this->databaseError(fn () => $second->transaction(fn () => $second->select($lockSql, [$relationshipId])));
        $this->assertTrue(Errors::isLockNotAvailable($blocked), "EndFullSecondment's own lock attempt waits on RecordEmploymentStatusPeriod's (lock_timeout fired)");

        $first->commit();

        $second->transaction(function () use ($second, $lockSql, $relationshipId, $openPeriodId): void {
            $second->select($lockSql, [$relationshipId]);
            $second->update('update hr.full_secondment_periods set effective_to = ? where id = ?', ['2026-06-01', $openPeriodId]);
        });

        $this->assertSame('2026-06-01', (string) $this->scalar('select effective_to from hr.full_secondment_periods where id = ?', [$openPeriodId]));
    }

    /**
     * The core new S15 race: EndEmploymentRelationship's new secondment-closing consequence
     * (spec §8.1) racing a concurrent, independent EndFullSecondment call against the very same
     * open secondment. Proves the relationship row lock genuinely serialises them, so the
     * later-committing session observes the first session's already-committed close (no open
     * secondment left to close) rather than a stale pre-race snapshot — exactly the same proof
     * shape as the pre-existing full-secondment-start-and-end race test, for the new S15 pairing.
     */
    public function test_concurrent_employment_end_and_full_secondment_end_for_the_same_relationship_are_serialised_by_the_relationship_row_lock(): void
    {
        $personId = $this->person();
        $employmentTypeId = $this->permanentEmploymentTypeId();
        $relationshipId = (string) Str::uuid7();
        $this->pg()->insert($this->insertSql(), [$relationshipId, $personId, $employmentTypeId, 'PN-EMPLOYMENT-END-SECONDMENT-END-RACE', 'PERMANENT', '2026-01-01']);
        $unitId = $this->organizationalUnitId();
        $openPeriodId = (string) Str::uuid7();
        $this->pg()->insert(<<<'SQL'
            insert into hr.full_secondment_periods
                (id, employment_relationship_id, organizational_unit_id, effective_from, created_at)
            values (?, ?, ?, ?, now())
            SQL, [$openPeriodId, $relationshipId, $unitId, '2026-02-01']);

        $lockSql = 'select id from hr.employment_relationships where id = ? for update';
        $endRelationshipSql = "update hr.employment_relationships set effective_to = ?, end_knowledge_state = 'KNOWN', version = 2 where id = ? and version = 1 and end_knowledge_state != 'KNOWN'";
        $openSecondmentSql = 'select id from hr.full_secondment_periods where employment_relationship_id = ? and effective_to is null';
        $closeSecondmentSql = 'update hr.full_secondment_periods set effective_to = ? where id = ?';

        $first = $this->pg();
        $second = $this->second();

        // Session 1 simulates EndEmploymentRelationship's own sequence (spec §8.1): scoped UPDATE
        // on the relationship, then — still inside the same, uncommitted transaction — closing the
        // open secondment it finds.
        $first->beginTransaction();
        $first->update($endRelationshipSql, ['2026-06-01', $relationshipId]);

        $second->statement("set lock_timeout = '300ms'");
        // Session 2 simulates an independent, concurrent EndFullSecondment call: its own lock
        // attempt must wait behind session 1's still-open transaction, not merely behind the
        // (different) secondment-period row it will eventually try to update.
        $blocked = $this->databaseError(fn () => $second->transaction(fn () => $second->select($lockSql, [$relationshipId])));
        $this->assertTrue(Errors::isLockNotAvailable($blocked), "the concurrent EndFullSecondment call's relationship-row lock attempt waits on EndEmploymentRelationship's (lock_timeout fired)");

        // Session 1 completes its own secondment close and commits.
        $first->update($closeSecondmentSql, ['2026-06-01', $openPeriodId]);
        $first->commit();

        // Now unblocked: session 2 must observe session 1's committed close — no open secondment
        // left to act on — proving genuine serialisation, not a stale pre-race snapshot that would
        // have let it double-close or race the same row.
        $second->transaction(function () use ($second, $lockSql, $relationshipId, $openSecondmentSql): void {
            $second->select($lockSql, [$relationshipId]);
            $stillOpen = $second->select($openSecondmentSql, [$relationshipId]);
            $this->assertCount(0, $stillOpen, 'the now-unblocked EndFullSecondment call must see no open secondment — the first session already closed it');
        });

        $this->assertSame('2026-06-01', (string) $this->scalar('select effective_to from hr.full_secondment_periods where id = ?', [$openPeriodId]));
    }

    /**
     * Adversarial-review finding on an earlier draft of EmploymentRelationshipController::end():
     * its audit-metadata "before" snapshot (hadOpenSecondment) was read via a plain, unlocked
     * SELECT before any transaction opened, so a genuinely concurrent StartFullSecondment could
     * commit a brand-new open secondment in the gap between that read and EndEmploymentRelationship's
     * own locked close — producing a false NEGATIVE full_secondment_closed_as_consequence audit
     * flag (the relationship-end call would genuinely close the newly-raced-in secondment, but the
     * stale "before" snapshot would never know one existed to close). Fixed by locking the
     * relationship row FIRST, in an outer transaction, before taking the snapshot (mirroring
     * TransferController's own established TOCTOU-closing shape) — this test proves that lock
     * genuinely blocks a concurrent StartFullSecondment's own identical lock attempt, closing the
     * race window the plain-SELECT draft left open.
     */
    public function test_the_employment_end_audit_snapshots_relationship_row_lock_blocks_a_concurrent_full_secondment_start(): void
    {
        $personId = $this->person();
        $employmentTypeId = $this->permanentEmploymentTypeId();
        $relationshipId = (string) Str::uuid7();
        $this->pg()->insert($this->insertSql(), [$relationshipId, $personId, $employmentTypeId, 'PN-END-AUDIT-SNAPSHOT-RACE', 'PERMANENT', '2026-01-01']);
        $unitId = $this->organizationalUnitId();

        $lockSql = 'select id from hr.employment_relationships where id = ? for update';
        $startSql = <<<'SQL'
            insert into hr.full_secondment_periods
                (id, employment_relationship_id, organizational_unit_id, effective_from, created_at)
            values (?, ?, ?, ?, now())
            SQL;

        $first = $this->pg();
        $second = $this->second();

        // Session 1 simulates EmploymentRelationshipController::end()'s own outer transaction:
        // lock the relationship row FIRST — before taking the hadOpenSecondment "before" snapshot
        // (a plain SELECT ... exists() here would find none, correctly, since nothing is open yet;
        // the point under test is that nothing else can change that between here and this
        // transaction's own commit).
        $first->beginTransaction();
        $first->select($lockSql, [$relationshipId]); // not committed yet

        $second->statement("set lock_timeout = '300ms'");
        // A concurrent StartFullSecondment call's own first statement (its own identical lock)
        // must wait behind session 1's still-open transaction — it cannot sneak a brand-new open
        // secondment into existence in the gap the earlier, unlocked draft left open.
        $blocked = $this->databaseError(fn () => $second->transaction(fn () => $second->select($lockSql, [$relationshipId])));
        $this->assertTrue(Errors::isLockNotAvailable($blocked), "a concurrent StartFullSecondment's lock attempt waits on the /end route's own snapshot-taking lock (lock_timeout fired)");

        // Session 1 (the /end call) finds nothing open, as its own snapshot correctly observed
        // under the lock, and commits without closing anything.
        $first->commit();

        // Only now, after session 1's transaction has fully committed, can the concurrent
        // StartFullSecondment proceed — proving it was genuinely blocked for the snapshot's entire
        // window, not merely delayed by coincidence.
        $second->transaction(function () use ($second, $lockSql, $startSql, $relationshipId, $unitId): void {
            $second->select($lockSql, [$relationshipId]);
            $second->insert($startSql, [(string) Str::uuid7(), $relationshipId, $unitId, '2026-06-01']);
        });

        $this->assertSame(1, (int) $this->scalar('select count(*) from hr.full_secondment_periods where employment_relationship_id = ?', [$relationshipId]));
    }
}
