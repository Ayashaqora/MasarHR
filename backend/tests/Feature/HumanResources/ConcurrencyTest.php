<?php

namespace Tests\Feature\HumanResources;

use App\Modules\HumanResources\Application\Commands\EndEmploymentRelationship;
use App\Modules\HumanResources\Application\Commands\RecordEmploymentStatusPeriod;
use App\Modules\HumanResources\Application\Commands\RecordReturnIntention;
use App\Modules\HumanResources\Application\Commands\RecordWorkSchedulePeriod;
use App\Modules\HumanResources\Application\Commands\ScanMovementExpiryFollowUps;
use App\Modules\HumanResources\Application\Commands\StartWorkplaceAssignment;
use App\Modules\HumanResources\Domain\Exceptions\EmploymentRelationshipAlreadyEndedException;
use App\Modules\HumanResources\Domain\Exceptions\InvalidEndDateException;
use App\Modules\HumanResources\Domain\Exceptions\InvalidReturnIntentionPeriodDateException;
use App\Modules\HumanResources\Domain\Exceptions\InvalidWorkSchedulePeriodDateException;
use App\Modules\HumanResources\Domain\ExpiryFollowUpEmission;
use App\Modules\HumanResources\Domain\FollowUpSuppressionReason;
use App\Modules\HumanResources\Domain\TemporaryMovementType;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\EmploymentRelationship;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\Person;
use App\Modules\Organization\Infrastructure\Persistence\Eloquent\OrganizationalUnit;
use App\Modules\Platform\Application\Execution\CommandContext;
use App\Modules\Platform\Domain\Actor;
use App\Modules\Platform\Domain\CorrelationId;
use App\Modules\Platform\Domain\Source;
use App\Modules\Platform\Infrastructure\Persistence\Postgres\PostgresErrorClassifier as Errors;
use App\Modules\Reference\Infrastructure\Persistence\Eloquent\DecisionType;
use App\Modules\Reference\Infrastructure\Persistence\Eloquent\EmploymentStatusDetail;
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
            // full_secondment_periods, workplace_assignment_periods, and
            // organizational_placement_periods first: all three carry a RESTRICT FK to
            // employment_relationships, same most-dependent-first ordering as
            // employment_status_periods below.
            // automation.movement_expiry_followups (S31) carries RESTRICT FKs to the movement tables,
            // employment_relationships and org units, so it goes first.
            DB::table('automation.movement_expiry_followups')->whereIn('employment_relationship_id', $relationshipIds)->delete();
            // employment_category_periods (S20) likewise carries a RESTRICT FK to
            // employment_relationships.
            DB::table('hr.employment_category_periods')->whereIn('employment_relationship_id', $relationshipIds)->delete();
            // employment_contract_periods (S21) and employment_job_title_periods (S22) likewise.
            DB::table('hr.employment_contract_periods')->whereIn('employment_relationship_id', $relationshipIds)->delete();
            DB::table('hr.employment_job_title_periods')->whereIn('employment_relationship_id', $relationshipIds)->delete();
            // work_schedule_period_weekdays → work_schedule_periods (S29): membership first, then the
            // periods (RESTRICT FK to employment_relationships).
            $schedulePeriodIds = DB::table('hr.work_schedule_periods')->whereIn('employment_relationship_id', $relationshipIds)->pluck('id');
            DB::table('hr.work_schedule_period_weekdays')->whereIn('work_schedule_period_id', $schedulePeriodIds)->delete();
            DB::table('hr.work_schedule_periods')->whereIn('employment_relationship_id', $relationshipIds)->delete();
            // partial_secondment_period_weekdays → partial_secondment_periods (S30): same shape.
            DB::table('hr.partial_secondment_period_weekdays')->whereIn('employment_relationship_id', $relationshipIds)->delete();
            DB::table('hr.partial_secondment_periods')->whereIn('employment_relationship_id', $relationshipIds)->delete();
            DB::table('hr.full_secondment_periods')->whereIn('employment_relationship_id', $relationshipIds)->delete();
            DB::table('hr.workplace_assignment_periods')->whereIn('employment_relationship_id', $relationshipIds)->delete();
            DB::table('hr.organizational_placement_periods')->whereIn('employment_relationship_id', $relationshipIds)->delete();
            DB::table('hr.return_intention_periods')->whereIn('employment_relationship_id', $relationshipIds)->delete();
            DB::table('hr.employment_status_periods')->whereIn('employment_relationship_id', $relationshipIds)->delete();
            DB::table('hr.employment_relationships')->whereIn('person_id', $this->personIds)->delete();
            // person_qualifications (S23) carries a RESTRICT FK to hr.persons.
            DB::table('hr.person_qualifications')->whereIn('person_id', $this->personIds)->delete();
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

    // ---------------------------------------------------------------------
    // S16 — Workplace Assignment Foundation (spec §S16.18/§S16.23, ADR-S16-001 §19/§24's six
    // named real two-connection races)
    // ---------------------------------------------------------------------

    /**
     * "Assignment -> Assignment" (spec §S16.8: ALLOW, CLOSE-PREVIOUS-THEN-START-NEW), mirroring
     * test_concurrent_transfer_calls_for_the_same_relationship_are_serialised_by_the_relationship_row_lock
     * exactly: StartWorkplaceAssignment's own first statement is the identical
     * `SELECT ... FOR UPDATE` lock on hr.employment_relationships every other S09-S16 command
     * shares, so two independent, genuinely concurrent StartWorkplaceAssignment calls against the
     * SAME relationship must be serialised by it — and the second (later-committed) call's own
     * replace step must observe the first call's own newly-opened period as "the" open period to
     * close, not a stale pre-race snapshot.
     */
    public function test_concurrent_workplace_assignment_start_calls_for_the_same_relationship_are_serialised_by_the_relationship_row_lock(): void
    {
        $personId = $this->person();
        $employmentTypeId = $this->permanentEmploymentTypeId();
        $relationshipId = (string) Str::uuid7();
        $this->pg()->insert($this->insertSql(), [$relationshipId, $personId, $employmentTypeId, 'PN-ASSIGNMENT-START-START-RACE', 'PERMANENT', '2026-01-01']);

        $unitAId = $this->organizationalUnitId();
        $unitBId = $this->organizationalUnitId();

        $lockSql = 'select id from hr.employment_relationships where id = ? for update';
        $openPeriodSql = 'select id, organizational_unit_id from hr.workplace_assignment_periods where employment_relationship_id = ? and effective_to is null';
        $closePeriodSql = 'update hr.workplace_assignment_periods set effective_to = ? where id = ?';
        $openNewPeriodSql = <<<'SQL'
            insert into hr.workplace_assignment_periods
                (id, employment_relationship_id, organizational_unit_id, effective_from, created_at)
            values (?, ?, ?, ?, now())
            SQL;

        $first = $this->pg();
        $second = $this->second();

        // Simulates the first StartWorkplaceAssignment call's own lock step (session 1, assigning
        // to A — nothing open yet, so it simply inserts).
        $first->beginTransaction();
        $first->select($lockSql, [$relationshipId]); // not committed yet

        $second->statement("set lock_timeout = '300ms'");
        // Simulates a second, independent StartWorkplaceAssignment call (assigning the same
        // relationship to B) racing the first: it must wait behind the first session's lock.
        $blocked = $this->databaseError(fn () => $second->transaction(fn () => $second->select($lockSql, [$relationshipId])));
        $this->assertTrue(Errors::isLockNotAvailable($blocked), "the second concurrent StartWorkplaceAssignment call's relationship-row lock attempt waits on the first's (lock_timeout fired)");

        // First session completes its own start (nothing open -> plain insert of A) and commits.
        $first->insert($openNewPeriodSql, [(string) Str::uuid7(), $relationshipId, $unitAId, '2026-02-01']);
        $first->commit();

        // Now unblocked: the second session performs its own real start (replacing A with B),
        // proving the wait was genuine serialization and that it correctly observes the FIRST
        // call's own newly-committed A period as "the" open period to close, not a stale
        // pre-race snapshot showing nothing open.
        $second->transaction(function () use ($second, $lockSql, $relationshipId, $unitBId, $openPeriodSql, $closePeriodSql, $openNewPeriodSql, $unitAId): void {
            $second->select($lockSql, [$relationshipId]);
            $openForSecond = $second->selectOne($openPeriodSql, [$relationshipId]);
            $this->assertSame($unitAId, (string) $openForSecond->organizational_unit_id, 'the second, now-unblocked start must see the first call\'s own committed A period as open, not a stale pre-race snapshot');
            $second->update($closePeriodSql, ['2026-03-01', $openForSecond->id]);
            $second->insert($openNewPeriodSql, [(string) Str::uuid7(), $relationshipId, $unitBId, '2026-03-01']);
        });

        $openPeriods = DB::table('hr.workplace_assignment_periods')
            ->where('employment_relationship_id', $relationshipId)->whereNull('effective_to')->get();
        $this->assertCount(1, $openPeriods, 'exactly one open workplace assignment period survives two serialised, racing starts');
        $this->assertSame($unitBId, (string) $openPeriods->first()->organizational_unit_id);

        $this->assertSame(2, (int) $this->scalar('select count(*) from hr.workplace_assignment_periods where employment_relationship_id = ?', [$relationshipId]));
    }

    /**
     * "Assignment start/end race", mirroring
     * test_concurrent_full_secondment_start_and_end_calls_for_the_same_relationship_are_serialised_by_the_relationship_row_lock
     * exactly (StartWorkplaceAssignment's and EndWorkplaceAssignment's shared first step is the
     * identical relationship row lock).
     */
    public function test_concurrent_workplace_assignment_start_and_end_calls_for_the_same_relationship_are_serialised_by_the_relationship_row_lock(): void
    {
        $personId = $this->person();
        $employmentTypeId = $this->permanentEmploymentTypeId();
        $relationshipId = (string) Str::uuid7();
        $this->pg()->insert($this->insertSql(), [$relationshipId, $personId, $employmentTypeId, 'PN-ASSIGNMENT-START-END-RACE', 'PERMANENT', '2026-01-01']);

        $unitId = $this->organizationalUnitId();
        $openPeriodId = (string) Str::uuid7();

        // An already-open assignment for the "EndWorkplaceAssignment" session to close.
        $this->pg()->insert(<<<'SQL'
            insert into hr.workplace_assignment_periods
                (id, employment_relationship_id, organizational_unit_id, effective_from, created_at)
            values (?, ?, ?, ?, now())
            SQL, [$openPeriodId, $relationshipId, $unitId, '2026-02-01']);

        $lockSql = 'select id from hr.employment_relationships where id = ? for update';

        $first = $this->pg();
        $second = $this->second();

        // Simulates a concurrent StartWorkplaceAssignment's own first statement: lock the
        // relationship row.
        $first->beginTransaction();
        $first->select($lockSql, [$relationshipId]); // not committed yet

        $second->statement("set lock_timeout = '300ms'");
        // Simulates EndWorkplaceAssignment's own first statement, on an independent session: it
        // must wait behind the first session's lock, not merely behind a lock on the (different)
        // period row it will eventually update.
        $blocked = $this->databaseError(fn () => $second->transaction(fn () => $second->select($lockSql, [$relationshipId])));
        $this->assertTrue(Errors::isLockNotAvailable($blocked), "EndWorkplaceAssignment's relationship-row lock attempt waits on StartWorkplaceAssignment's (lock_timeout fired)");

        $first->commit();

        // Now unblocked: the second session performs EndWorkplaceAssignment's actual close,
        // proving the wait was genuine serialization, not a permanent deadlock or a false block on
        // an unrelated row.
        $second->transaction(function () use ($second, $lockSql, $relationshipId, $openPeriodId): void {
            $second->select($lockSql, [$relationshipId]);
            $second->update('update hr.workplace_assignment_periods set effective_to = ? where id = ?', ['2026-05-01', $openPeriodId]);
        });

        $this->assertSame('2026-05-01', (string) $this->scalar('select effective_to from hr.workplace_assignment_periods where id = ?', [$openPeriodId]));
    }

    /**
     * "Assignment -> Transfer" (spec §S16.8: ALLOW, CLOSE-PREVIOUS-AS-CONSEQUENCE), mirroring
     * test_concurrent_employment_end_and_full_secondment_end_for_the_same_relationship_are_serialised_by_the_relationship_row_lock's
     * shape: TransferEmployee's own consequence (closing an open assignment, spec §S16.8) racing a
     * concurrent, independent StartWorkplaceAssignment call. Proves the relationship row lock
     * genuinely serialises them, so the later-committing StartWorkplaceAssignment observes the
     * transfer's already-committed close (no open assignment left) rather than a stale pre-race
     * snapshot that would have let it try to replace an assignment the transfer already closed.
     */
    public function test_concurrent_transfer_and_workplace_assignment_start_for_the_same_relationship_are_serialised_by_the_relationship_row_lock(): void
    {
        $personId = $this->person();
        $employmentTypeId = $this->permanentEmploymentTypeId();
        $relationshipId = (string) Str::uuid7();
        $this->pg()->insert($this->insertSql(), [$relationshipId, $personId, $employmentTypeId, 'PN-ASSIGNMENT-TRANSFER-START-RACE', 'PERMANENT', '2026-01-01']);

        $sourceUnitId = $this->organizationalUnitId();
        $destinationUnitId = $this->organizationalUnitId();
        $assignmentUnitId = $this->organizationalUnitId();
        $newAssignmentUnitId = $this->organizationalUnitId();

        // The relationship's original placement (S11) — TransferEmployee's own source.
        $this->pg()->insert(<<<'SQL'
            insert into hr.organizational_placement_periods
                (id, employment_relationship_id, organizational_unit_id, effective_from, created_at)
            values (?, ?, ?, ?, now())
            SQL, [(string) Str::uuid7(), $relationshipId, $sourceUnitId, '2026-01-01']);

        // An already-open assignment for TransferEmployee's own consequence to close.
        $openAssignmentId = (string) Str::uuid7();
        $this->pg()->insert(<<<'SQL'
            insert into hr.workplace_assignment_periods
                (id, employment_relationship_id, organizational_unit_id, effective_from, created_at)
            values (?, ?, ?, ?, now())
            SQL, [$openAssignmentId, $relationshipId, $assignmentUnitId, '2026-02-01']);

        $lockSql = 'select id from hr.employment_relationships where id = ? for update';
        $openPlacementSql = 'select id from hr.organizational_placement_periods where employment_relationship_id = ? and effective_to is null';
        $openAssignmentSql = 'select id, organizational_unit_id from hr.workplace_assignment_periods where employment_relationship_id = ? and effective_to is null';
        $closeAssignmentSql = 'update hr.workplace_assignment_periods set effective_to = ? where id = ?';
        $openNewAssignmentSql = <<<'SQL'
            insert into hr.workplace_assignment_periods
                (id, employment_relationship_id, organizational_unit_id, effective_from, created_at)
            values (?, ?, ?, ?, now())
            SQL;

        $first = $this->pg();
        $second = $this->second();

        // Session 1 simulates TransferEmployee's own sequence: lock, close the open placement,
        // open the new one, and — as its S16 consequence — close the open assignment.
        $first->beginTransaction();
        $first->select($lockSql, [$relationshipId]); // not committed yet

        $second->statement("set lock_timeout = '300ms'");
        // Simulates a concurrent, independent StartWorkplaceAssignment call: its own lock attempt
        // must wait behind session 1's still-open transaction.
        $blocked = $this->databaseError(fn () => $second->transaction(fn () => $second->select($lockSql, [$relationshipId])));
        $this->assertTrue(Errors::isLockNotAvailable($blocked), "the concurrent StartWorkplaceAssignment call's relationship-row lock attempt waits on TransferEmployee's (lock_timeout fired)");

        $openPlacement = $first->selectOne($openPlacementSql, [$relationshipId]);
        $first->update('update hr.organizational_placement_periods set effective_to = ? where id = ?', ['2026-06-01', $openPlacement->id]);
        $first->insert(<<<'SQL'
            insert into hr.organizational_placement_periods
                (id, employment_relationship_id, organizational_unit_id, effective_from, created_at)
            values (?, ?, ?, ?, now())
            SQL, [(string) Str::uuid7(), $relationshipId, $destinationUnitId, '2026-06-01']);
        $first->update($closeAssignmentSql, ['2026-06-01', $openAssignmentId]);
        $first->commit();

        // Now unblocked: the second session's StartWorkplaceAssignment must observe the transfer's
        // already-committed close — no open assignment left to replace — and so performs a plain
        // insert, proving it did not race a stale pre-commit snapshot that would still show the
        // old assignment open.
        $second->transaction(function () use ($second, $lockSql, $relationshipId, $openAssignmentSql, $openNewAssignmentSql, $newAssignmentUnitId): void {
            $second->select($lockSql, [$relationshipId]);
            $stillOpen = $second->select($openAssignmentSql, [$relationshipId]);
            $this->assertCount(0, $stillOpen, 'the now-unblocked StartWorkplaceAssignment call must see no open assignment — the transfer already closed it as its own consequence');
            $second->insert($openNewAssignmentSql, [(string) Str::uuid7(), $relationshipId, $newAssignmentUnitId, '2026-06-01']);
        });

        $openAssignments = DB::table('hr.workplace_assignment_periods')
            ->where('employment_relationship_id', $relationshipId)->whereNull('effective_to')->get();
        $this->assertCount(1, $openAssignments, 'exactly one open assignment period survives the serialised transfer-then-start race');
        $this->assertSame($newAssignmentUnitId, (string) $openAssignments->first()->organizational_unit_id);
    }

    /**
     * "Assignment -> Transfer" (spec §S16.8: ALLOW, CLOSE-PREVIOUS-AS-CONSEQUENCE), the EndAssignment
     * variant of the race above: proves a concurrent, independent EndWorkplaceAssignment call —
     * rather than a StartWorkplaceAssignment — also correctly observes TransferEmployee's own
     * already-committed consequence-close, finding nothing left open to close itself (no
     * double-close, no stale pre-race snapshot).
     */
    public function test_concurrent_transfer_and_workplace_assignment_end_for_the_same_relationship_are_serialised_by_the_relationship_row_lock(): void
    {
        $personId = $this->person();
        $employmentTypeId = $this->permanentEmploymentTypeId();
        $relationshipId = (string) Str::uuid7();
        $this->pg()->insert($this->insertSql(), [$relationshipId, $personId, $employmentTypeId, 'PN-ASSIGNMENT-TRANSFER-END-RACE', 'PERMANENT', '2026-01-01']);

        $sourceUnitId = $this->organizationalUnitId();
        $destinationUnitId = $this->organizationalUnitId();
        $assignmentUnitId = $this->organizationalUnitId();

        $this->pg()->insert(<<<'SQL'
            insert into hr.organizational_placement_periods
                (id, employment_relationship_id, organizational_unit_id, effective_from, created_at)
            values (?, ?, ?, ?, now())
            SQL, [(string) Str::uuid7(), $relationshipId, $sourceUnitId, '2026-01-01']);

        $openAssignmentId = (string) Str::uuid7();
        $this->pg()->insert(<<<'SQL'
            insert into hr.workplace_assignment_periods
                (id, employment_relationship_id, organizational_unit_id, effective_from, created_at)
            values (?, ?, ?, ?, now())
            SQL, [$openAssignmentId, $relationshipId, $assignmentUnitId, '2026-02-01']);

        $lockSql = 'select id from hr.employment_relationships where id = ? for update';
        $openPlacementSql = 'select id from hr.organizational_placement_periods where employment_relationship_id = ? and effective_to is null';
        $openAssignmentSql = 'select id from hr.workplace_assignment_periods where employment_relationship_id = ? and effective_to is null';
        $closeAssignmentSql = 'update hr.workplace_assignment_periods set effective_to = ? where id = ?';

        $first = $this->pg();
        $second = $this->second();

        $first->beginTransaction();
        $first->select($lockSql, [$relationshipId]); // simulates TransferEmployee's own lock, not committed yet

        $second->statement("set lock_timeout = '300ms'");
        // Simulates a concurrent, independent EndWorkplaceAssignment call against the very same
        // open assignment the transfer is about to close.
        $blocked = $this->databaseError(fn () => $second->transaction(fn () => $second->select($lockSql, [$relationshipId])));
        $this->assertTrue(Errors::isLockNotAvailable($blocked), "the concurrent EndWorkplaceAssignment call's relationship-row lock attempt waits on TransferEmployee's (lock_timeout fired)");

        $openPlacement = $first->selectOne($openPlacementSql, [$relationshipId]);
        $first->update('update hr.organizational_placement_periods set effective_to = ? where id = ?', ['2026-06-01', $openPlacement->id]);
        $first->insert(<<<'SQL'
            insert into hr.organizational_placement_periods
                (id, employment_relationship_id, organizational_unit_id, effective_from, created_at)
            values (?, ?, ?, ?, now())
            SQL, [(string) Str::uuid7(), $relationshipId, $destinationUnitId, '2026-06-01']);
        $first->update($closeAssignmentSql, ['2026-06-01', $openAssignmentId]);
        $first->commit();

        // Now unblocked: the second session's EndWorkplaceAssignment must observe that the
        // transfer already closed the assignment — nothing left open to act on, proving genuine
        // serialisation rather than a stale pre-race snapshot that would have let it double-close
        // the same row.
        $second->transaction(function () use ($second, $lockSql, $relationshipId, $openAssignmentSql): void {
            $second->select($lockSql, [$relationshipId]);
            $stillOpen = $second->select($openAssignmentSql, [$relationshipId]);
            $this->assertCount(0, $stillOpen, 'the now-unblocked EndWorkplaceAssignment call must see no open assignment — the transfer already closed it as its own consequence');
        });

        $this->assertSame('2026-06-01', (string) $this->scalar('select effective_to from hr.workplace_assignment_periods where id = ?', [$openAssignmentId]));
    }

    /**
     * "Full Secondment -> Assignment" mutual exclusion (spec §S16.8: REJECT — a conservative
     * mutual exclusion, no invented cross-domain precedence), mirroring
     * test_concurrent_status_recording_and_full_secondment_start_for_the_same_relationship_are_serialised_by_the_relationship_row_lock's
     * shape: proves a concurrent, independent StartFullSecondment call — racing a
     * StartWorkplaceAssignment call that commits first — genuinely observes the just-committed
     * assignment once unblocked (the row lock closes the race window a mutual-exclusion check like
     * this depends on), rather than a stale pre-race snapshot that would have let both movement
     * types coexist.
     */
    public function test_concurrent_workplace_assignment_start_and_full_secondment_start_for_the_same_relationship_are_serialised_by_the_relationship_row_lock(): void
    {
        $personId = $this->person();
        $employmentTypeId = $this->permanentEmploymentTypeId();
        $relationshipId = (string) Str::uuid7();
        $this->pg()->insert($this->insertSql(), [$relationshipId, $personId, $employmentTypeId, 'PN-ASSIGNMENT-SECONDMENT-START-RACE', 'PERMANENT', '2026-01-01']);
        $assignmentUnitId = $this->organizationalUnitId();

        $lockSql = 'select id from hr.employment_relationships where id = ? for update';
        $openAssignmentSql = 'select id from hr.workplace_assignment_periods where employment_relationship_id = ? and effective_to is null';
        $startAssignmentSql = <<<'SQL'
            insert into hr.workplace_assignment_periods
                (id, employment_relationship_id, organizational_unit_id, effective_from, created_at)
            values (?, ?, ?, ?, now())
            SQL;

        $first = $this->pg();
        $second = $this->second();

        // Session 1 simulates StartWorkplaceAssignment's own sequence: lock, then (nothing open,
        // no active secondment) insert the new open assignment period.
        $first->beginTransaction();
        $first->select($lockSql, [$relationshipId]); // not committed yet

        $second->statement("set lock_timeout = '300ms'");
        // Simulates a concurrent, independent StartFullSecondment call's own lock attempt — it
        // must wait behind session 1's still-open transaction, not merely behind a lock on the
        // (different) assignment-period row session 1 is about to insert.
        $blocked = $this->databaseError(fn () => $second->transaction(fn () => $second->select($lockSql, [$relationshipId])));
        $this->assertTrue(Errors::isLockNotAvailable($blocked), "StartFullSecondment's own lock attempt waits on StartWorkplaceAssignment's (lock_timeout fired)");

        $first->insert($startAssignmentSql, [(string) Str::uuid7(), $relationshipId, $assignmentUnitId, '2026-02-01']);
        $first->commit();

        // Now unblocked: StartFullSecondment's own mutual-exclusion check (spec §S16.8) queries
        // for an active assignment and must genuinely observe the first session's own
        // now-committed row — proving the shared lock closes the race window this check depends
        // on, rather than a stale pre-race snapshot that would have let both movement types
        // coexist.
        $second->transaction(function () use ($second, $lockSql, $relationshipId, $openAssignmentSql): void {
            $second->select($lockSql, [$relationshipId]);
            $activeAssignment = $second->select($openAssignmentSql, [$relationshipId]);
            $this->assertCount(1, $activeAssignment, "StartFullSecondment's now-unblocked mutual-exclusion check must see the concurrently-committed assignment, proving no race window for the REJECT decision");
        });

        $this->assertSame(1, (int) $this->scalar('select count(*) from hr.workplace_assignment_periods where employment_relationship_id = ?', [$relationshipId]));
    }

    /**
     * The core new S16 race, mirroring
     * test_concurrent_employment_end_and_full_secondment_end_for_the_same_relationship_are_serialised_by_the_relationship_row_lock
     * exactly for the new closeOpenWorkplaceAssignmentIfAny() consequence (spec §S16.8/§S16.10):
     * EndEmploymentRelationship's assignment-closing consequence racing a concurrent, independent
     * StartWorkplaceAssignment call — a relationship that is already ended must never let a
     * concurrent start slip in and open a new, orphaned assignment period after the fact. Also
     * covers the EmploymentRelationshipController::end() audit-metadata snapshot fix (mirroring
     * test_the_employment_end_audit_snapshots_relationship_row_lock_blocks_a_concurrent_full_secondment_start):
     * the row lock taken before the hadOpenAssignment "before" snapshot must block a concurrent
     * StartWorkplaceAssignment for its entire window, not merely by coincidence.
     */
    public function test_concurrent_employment_end_and_workplace_assignment_start_for_the_same_relationship_are_serialised_by_the_relationship_row_lock(): void
    {
        $personId = $this->person();
        $employmentTypeId = $this->permanentEmploymentTypeId();
        $relationshipId = (string) Str::uuid7();
        $this->pg()->insert($this->insertSql(), [$relationshipId, $personId, $employmentTypeId, 'PN-EMPLOYMENT-END-ASSIGNMENT-START-RACE', 'PERMANENT', '2026-01-01']);

        $lockSql = 'select id from hr.employment_relationships where id = ? for update';
        $endRelationshipSql = "update hr.employment_relationships set effective_to = ?, end_knowledge_state = 'KNOWN', version = 2 where id = ? and version = 1 and end_knowledge_state != 'KNOWN'";

        $first = $this->pg();
        $second = $this->second();

        // Session 1 simulates EmploymentRelationshipController::end()'s own outer transaction:
        // lock the relationship row FIRST (taking its hadOpenAssignment "before" snapshot under
        // the lock — nothing open yet, correctly), then EndEmploymentRelationship's own scoped
        // UPDATE.
        $first->beginTransaction();
        $first->select($lockSql, [$relationshipId]); // not committed yet

        $second->statement("set lock_timeout = '300ms'");
        // A concurrent StartWorkplaceAssignment call's own first statement (its own identical
        // lock) must wait behind session 1's still-open transaction — it cannot sneak a brand-new
        // open assignment into existence in the gap between the snapshot and the commit.
        $blocked = $this->databaseError(fn () => $second->transaction(fn () => $second->select($lockSql, [$relationshipId])));
        $this->assertTrue(Errors::isLockNotAvailable($blocked), "a concurrent StartWorkplaceAssignment's lock attempt waits on the /end route's own snapshot-taking lock (lock_timeout fired)");

        $first->update($endRelationshipSql, ['2026-06-01', $relationshipId]);
        $first->commit();

        // Only now, after session 1's transaction has fully committed, can the concurrent
        // StartWorkplaceAssignment proceed. In the real command, its own end_knowledge_state check
        // (mirroring the fresh-relationship re-fetch every S09-S16 command performs) would reject
        // this with EmploymentRelationshipAlreadyEndedException — this test proves the lock
        // genuinely blocked the attempt for the snapshot's entire window rather than merely
        // delaying it, so that check is never racing a stale pre-end snapshot.
        $second->transaction(function () use ($second, $relationshipId): void {
            $endedState = $second->selectOne('select end_knowledge_state from hr.employment_relationships where id = ? for update', [$relationshipId]);
            $this->assertSame('KNOWN', (string) $endedState->end_knowledge_state, 'the now-unblocked StartWorkplaceAssignment attempt must observe the relationship as already ended, not a stale pre-race snapshot');
        });

        $this->assertSame(0, (int) $this->scalar('select count(*) from hr.workplace_assignment_periods where employment_relationship_id = ?', [$relationshipId]));
    }

    private function employmentCategoryId(string $code): string
    {
        return (string) DB::table('ref.employment_categories')->where('code', $code)->value('id');
    }

    /**
     * S20 (docs/employment-category-history-foundation-specification.md §S20.8): the database-level
     * backstop, independent of RecordEmploymentCategoryPeriod's own relationship-row lock — two
     * real, independent sessions racing overlapping inserts for the same employment_relationship_id
     * cannot both commit; the EXCLUDE constraint serialises and then rejects the loser. Mirrors
     * test_concurrent_overlapping_status_periods_for_the_same_relationship_are_serialised_by_the_exclusion_constraint.
     */
    public function test_concurrent_overlapping_employment_category_periods_for_the_same_relationship_are_serialised_by_the_exclusion_constraint(): void
    {
        $personId = $this->person();
        $relationshipId = (string) Str::uuid7();
        $this->pg()->insert($this->insertSql(), [$relationshipId, $personId, $this->permanentEmploymentTypeId(), 'PN-CATEGORY-RACE', 'PERMANENT', '2026-01-01']);

        $periodInsertSql = <<<'SQL'
            insert into hr.employment_category_periods
                (id, employment_relationship_id, employment_category_id, effective_from, created_at)
            values (?, ?, ?, ?, now())
            SQL;

        $first = $this->pg();
        $second = $this->second();

        $first->beginTransaction();
        $first->insert($periodInsertSql, [(string) Str::uuid7(), $relationshipId, $this->employmentCategoryId('grade_3'), '2026-02-01']); // not committed yet

        $second->statement("set lock_timeout = '300ms'");
        $blocked = $this->databaseError(fn () => $second->transaction(fn () => $second->insert($periodInsertSql, [
            (string) Str::uuid7(), $relationshipId, $this->employmentCategoryId('grade_2'), '2026-03-01',
        ])));
        $this->assertTrue(Errors::isLockNotAvailable($blocked), 'the second session waits on the first (lock_timeout fired)');

        $first->commit();

        $rejected = $this->databaseError(fn () => $second->transaction(fn () => $second->insert($periodInsertSql, [
            (string) Str::uuid7(), $relationshipId, $this->employmentCategoryId('grade_2'), '2026-03-01',
        ])));
        $this->assertTrue(Errors::isExclusionViolation($rejected), 'once committed, the overlap is rejected by PostgreSQL itself, not merely delayed');

        $this->assertSame(1, (int) $this->scalar('select count(*) from hr.employment_category_periods where employment_relationship_id = ?', [$relationshipId]));
    }

    /**
     * S20 §S20.8/§S20.11: RecordEmploymentCategoryPeriod's first statement (SELECT ... FOR UPDATE
     * on the relationship) and EndEmploymentRelationship's scoped UPDATE contend for the same row
     * lock, so a category can never be recorded against a relationship that a concurrent end is
     * about to close, nor slip in between the end's own category-bounds check and its commit. The
     * unblocked recorder must observe the relationship as already ended (and reject, per
     * RecordEmploymentCategoryPeriod's end_knowledge_state check).
     */
    public function test_concurrent_category_recording_and_employment_end_for_the_same_relationship_are_serialised_by_the_relationship_row_lock(): void
    {
        $personId = $this->person();
        $relationshipId = (string) Str::uuid7();
        $this->pg()->insert($this->insertSql(), [$relationshipId, $personId, $this->permanentEmploymentTypeId(), 'PN-CATEGORY-END-RACE', 'PERMANENT', '2026-01-01']);

        $lockSql = 'select id from hr.employment_relationships where id = ? for update';
        $endSql = "update hr.employment_relationships set effective_to = ?, end_knowledge_state = 'KNOWN', version = 2 where id = ? and version = 1 and end_knowledge_state != 'KNOWN'";

        $first = $this->pg();
        $second = $this->second();

        // Session 1 simulates EndEmploymentRelationship: scoped UPDATE (implicit row lock).
        $first->beginTransaction();
        $first->update($endSql, ['2026-06-01', $relationshipId]); // not committed yet

        $second->statement("set lock_timeout = '300ms'");
        // Session 2 simulates RecordEmploymentCategoryPeriod's own first statement.
        $blocked = $this->databaseError(fn () => $second->transaction(fn () => $second->select($lockSql, [$relationshipId])));
        $this->assertTrue(Errors::isLockNotAvailable($blocked), "RecordEmploymentCategoryPeriod's lock waits on the in-flight relationship end (lock_timeout fired)");

        $first->commit();

        $second->transaction(function () use ($second, $relationshipId): void {
            $state = $second->selectOne('select end_knowledge_state from hr.employment_relationships where id = ? for update', [$relationshipId]);
            $this->assertSame('KNOWN', (string) $state->end_knowledge_state, 'the unblocked recorder observes the committed end, never a stale snapshot');
        });

        $this->assertSame(0, (int) $this->scalar('select count(*) from hr.employment_category_periods where employment_relationship_id = ?', [$relationshipId]));
    }

    /**
     * S21 (docs/employment-contract-foundation-specification.md §S21.8): the database-level backstop
     * for contract periods — two real, independent sessions racing overlapping inserts for the same
     * relationship cannot both commit. The contract type is inserted and committed here (no contract
     * type is seeded — S13 refused to invent values) and removed in cleanup.
     */
    public function test_concurrent_overlapping_employment_contract_periods_for_the_same_relationship_are_serialised_by_the_exclusion_constraint(): void
    {
        $personId = $this->person();
        $relationshipId = (string) Str::uuid7();
        $this->pg()->insert($this->insertSql(), [$relationshipId, $personId, $this->contractEmploymentTypeId(), 'CN-RACE-'.Str::random(6), 'CONTRACT', '2026-01-01']);
        $contractTypeId = $this->contractTypeId();

        $periodInsertSql = <<<'SQL'
            insert into hr.employment_contract_periods
                (id, employment_relationship_id, contract_type_id, effective_from, effective_to,
                 contractual_effective_to, contract_end_knowledge_state, created_at)
            values (?, ?, ?, ?, ?, ?, 'KNOWN', now())
            SQL;

        $first = $this->pg();
        $second = $this->second();

        try {
            $first->beginTransaction();
            $first->insert($periodInsertSql, [(string) Str::uuid7(), $relationshipId, $contractTypeId, '2026-01-01', '2027-01-01', '2027-01-01']); // not committed yet

            $second->statement("set lock_timeout = '300ms'");
            $blocked = $this->databaseError(fn () => $second->transaction(fn () => $second->insert($periodInsertSql, [
                (string) Str::uuid7(), $relationshipId, $contractTypeId, '2026-06-01', '2027-06-01', '2027-06-01',
            ])));
            $this->assertTrue(Errors::isLockNotAvailable($blocked), 'the second session waits on the first (lock_timeout fired)');

            $first->commit();

            $rejected = $this->databaseError(fn () => $second->transaction(fn () => $second->insert($periodInsertSql, [
                (string) Str::uuid7(), $relationshipId, $contractTypeId, '2026-06-01', '2027-06-01', '2027-06-01',
            ])));
            $this->assertTrue(Errors::isExclusionViolation($rejected), 'once committed, the overlap is rejected by PostgreSQL itself');

            $this->assertSame(1, (int) $this->scalar('select count(*) from hr.employment_contract_periods where employment_relationship_id = ?', [$relationshipId]));
        } finally {
            $this->cleanUpContractPeriodsAndType($relationshipId, $contractTypeId);
        }
    }

    /**
     * S21 §S21.8/§S21.10: two concurrent renewals (or a renewal and a relationship end) of the same
     * relationship contend for the same relationship row lock that RecordEmploymentContractPeriod
     * takes first, so the second can only ever run against the first one's committed result — a
     * stale renewal can never truncate or overlap history computed from an out-of-date "latest
     * period".
     */
    public function test_concurrent_contract_renewals_for_the_same_relationship_are_serialised_by_the_relationship_row_lock(): void
    {
        $personId = $this->person();
        $relationshipId = (string) Str::uuid7();
        $this->pg()->insert($this->insertSql(), [$relationshipId, $personId, $this->contractEmploymentTypeId(), 'CN-RENEW-'.Str::random(6), 'CONTRACT', '2026-01-01']);

        $lockSql = 'select id from hr.employment_relationships where id = ? for update';
        $first = $this->pg();
        $second = $this->second();

        $first->beginTransaction();
        $first->select($lockSql, [$relationshipId]); // renewal #1 holds the lock, not committed yet

        $second->statement("set lock_timeout = '300ms'");
        $blocked = $this->databaseError(fn () => $second->transaction(fn () => $second->select($lockSql, [$relationshipId])));
        $this->assertTrue(Errors::isLockNotAvailable($blocked), 'renewal #2 waits on renewal #1 (lock_timeout fired)');

        $first->commit();

        $second->transaction(fn () => $second->select($lockSql, [$relationshipId]));
        $this->assertSame(0, (int) $this->scalar('select count(*) from hr.employment_contract_periods where employment_relationship_id = ?', [$relationshipId]));
    }

    private function contractEmploymentTypeId(): string
    {
        return (string) DB::table('ref.employment_types')->where('code', 'contract')->value('id');
    }

    /** Committed directly so the independent second session can see it; removed in cleanup. */
    private function contractTypeId(): string
    {
        $id = (string) Str::uuid7();
        DB::table('ref.contract_types')->insert([
            'id' => $id,
            'code' => 's21_race_'.Str::lower(Str::random(8)),
            'name_ar' => 'نوع عقد اختبار',
            'name_en' => null,
            'is_active' => true,
            'display_order' => 99,
            'version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function cleanUpContractPeriodsAndType(string $relationshipId, string $contractTypeId): void
    {
        foreach ([DB::connection(), $this->hasSecond() ? $this->second() : null] as $connection) {
            while ($connection !== null && $connection->transactionLevel() > 0) {
                $connection->rollBack();
            }
        }

        DB::table('hr.employment_contract_periods')->where('employment_relationship_id', $relationshipId)->delete();
        DB::table('ref.contract_types')->where('id', $contractTypeId)->delete();
    }

    /**
     * S22 (docs/employment-job-title-history-foundation-specification.md §S22.8): the database
     * backstop for job title periods — two real, independent sessions racing overlapping inserts
     * for the same relationship cannot both commit. The job title is committed here (none is
     * seeded — S13 forbade fabricating titles) and removed in cleanup.
     */
    public function test_concurrent_overlapping_employment_job_title_periods_for_the_same_relationship_are_serialised_by_the_exclusion_constraint(): void
    {
        $personId = $this->person();
        $relationshipId = (string) Str::uuid7();
        $this->pg()->insert($this->insertSql(), [$relationshipId, $personId, $this->permanentEmploymentTypeId(), 'PN-TITLE-RACE-'.Str::random(6), 'PERMANENT', '2026-01-01']);
        $jobTitleId = $this->jobTitleId();

        $periodInsertSql = <<<'SQL'
            insert into hr.employment_job_title_periods
                (id, employment_relationship_id, job_title_id, effective_from, start_knowledge_state, created_at)
            values (?, ?, ?, ?, 'KNOWN', now())
            SQL;

        $first = $this->pg();
        $second = $this->second();

        try {
            $first->beginTransaction();
            $first->insert($periodInsertSql, [(string) Str::uuid7(), $relationshipId, $jobTitleId, '2026-02-01']); // not committed yet

            $second->statement("set lock_timeout = '300ms'");
            $blocked = $this->databaseError(fn () => $second->transaction(fn () => $second->insert($periodInsertSql, [
                (string) Str::uuid7(), $relationshipId, $jobTitleId, '2026-03-01',
            ])));
            $this->assertTrue(Errors::isLockNotAvailable($blocked), 'the second session waits on the first (lock_timeout fired)');

            $first->commit();

            $rejected = $this->databaseError(fn () => $second->transaction(fn () => $second->insert($periodInsertSql, [
                (string) Str::uuid7(), $relationshipId, $jobTitleId, '2026-03-01',
            ])));
            $this->assertTrue(Errors::isExclusionViolation($rejected), 'once committed, the overlap is rejected by PostgreSQL itself');

            $this->assertSame(1, (int) $this->scalar('select count(*) from hr.employment_job_title_periods where employment_relationship_id = ?', [$relationshipId]));
        } finally {
            foreach ([DB::connection(), $this->hasSecond() ? $this->second() : null] as $connection) {
                while ($connection !== null && $connection->transactionLevel() > 0) {
                    $connection->rollBack();
                }
            }
            DB::table('hr.employment_job_title_periods')->where('employment_relationship_id', $relationshipId)->delete();
            DB::table('ref.job_titles')->where('id', $jobTitleId)->delete();
        }
    }

    /**
     * S22 §S22.8/§S22.11: RecordEmploymentJobTitlePeriod's first statement (SELECT ... FOR UPDATE on
     * the relationship) waits behind an in-flight EndEmploymentRelationship's scoped UPDATE, so a
     * title can never be recorded against a relationship a concurrent end is about to close, nor
     * slip between the end's bounds check and its commit.
     */
    public function test_concurrent_job_title_recording_and_employment_end_are_serialised_by_the_relationship_row_lock(): void
    {
        $personId = $this->person();
        $relationshipId = (string) Str::uuid7();
        $this->pg()->insert($this->insertSql(), [$relationshipId, $personId, $this->permanentEmploymentTypeId(), 'PN-TITLE-END-'.Str::random(6), 'PERMANENT', '2026-01-01']);

        $lockSql = 'select id from hr.employment_relationships where id = ? for update';
        $endSql = "update hr.employment_relationships set effective_to = ?, end_knowledge_state = 'KNOWN', version = 2 where id = ? and version = 1 and end_knowledge_state != 'KNOWN'";

        $first = $this->pg();
        $second = $this->second();

        $first->beginTransaction();
        $first->update($endSql, ['2026-06-01', $relationshipId]); // not committed yet

        $second->statement("set lock_timeout = '300ms'");
        $blocked = $this->databaseError(fn () => $second->transaction(fn () => $second->select($lockSql, [$relationshipId])));
        $this->assertTrue(Errors::isLockNotAvailable($blocked), 'the job title recorder waits on the in-flight end');

        $first->commit();

        $second->transaction(function () use ($second, $relationshipId): void {
            $state = $second->selectOne('select end_knowledge_state from hr.employment_relationships where id = ? for update', [$relationshipId]);
            $this->assertSame('KNOWN', (string) $state->end_knowledge_state, 'the unblocked recorder sees the committed end, never a stale snapshot');
        });

        $this->assertSame(0, (int) $this->scalar('select count(*) from hr.employment_job_title_periods where employment_relationship_id = ?', [$relationshipId]));
    }

    /**
     * S26 (docs/employee-specialty-history-foundation-specification.md §S26.8): the database
     * backstop for employee specialty periods — two real, independent sessions racing overlapping
     * inserts for the same relationship cannot both commit (write skew is impossible). The
     * specialty is committed here (none is seeded — ADR-S25-001 D) and removed in cleanup.
     */
    public function test_concurrent_overlapping_employment_specialty_periods_for_the_same_relationship_are_serialised_by_the_exclusion_constraint(): void
    {
        $personId = $this->person();
        $relationshipId = (string) Str::uuid7();
        $this->pg()->insert($this->insertSql(), [$relationshipId, $personId, $this->permanentEmploymentTypeId(), 'PN-SPEC-RACE-'.Str::random(6), 'PERMANENT', '2026-01-01']);
        $specialtyId = (string) Str::uuid7();
        DB::table('ref.specialties')->insert([
            'id' => $specialtyId, 'code' => 's26_race_'.Str::lower(Str::random(8)), 'name_ar' => 'تخصص اختبار', 'name_en' => null,
            'is_active' => true, 'display_order' => 99, 'version' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $periodInsertSql = <<<'SQL'
            insert into hr.employment_specialty_periods
                (id, employment_relationship_id, specialty_id, effective_from, created_at)
            values (?, ?, ?, ?, now())
            SQL;

        $first = $this->pg();
        $second = $this->second();

        try {
            $first->beginTransaction();
            $first->insert($periodInsertSql, [(string) Str::uuid7(), $relationshipId, $specialtyId, '2026-02-01']); // not committed yet

            $second->statement("set lock_timeout = '300ms'");
            $blocked = $this->databaseError(fn () => $second->transaction(fn () => $second->insert($periodInsertSql, [
                (string) Str::uuid7(), $relationshipId, $specialtyId, '2026-03-01',
            ])));
            $this->assertTrue(Errors::isLockNotAvailable($blocked), 'the second session waits on the first (lock_timeout fired)');

            $first->commit();

            $rejected = $this->databaseError(fn () => $second->transaction(fn () => $second->insert($periodInsertSql, [
                (string) Str::uuid7(), $relationshipId, $specialtyId, '2026-03-01',
            ])));
            $this->assertTrue(Errors::isExclusionViolation($rejected), 'once committed, the overlap is rejected by PostgreSQL itself');

            $this->assertSame(1, (int) $this->scalar('select count(*) from hr.employment_specialty_periods where employment_relationship_id = ?', [$relationshipId]));
        } finally {
            foreach ([DB::connection(), $this->hasSecond() ? $this->second() : null] as $connection) {
                while ($connection !== null && $connection->transactionLevel() > 0) {
                    $connection->rollBack();
                }
            }
            DB::table('hr.employment_specialty_periods')->where('employment_relationship_id', $relationshipId)->delete();
            DB::table('ref.specialties')->where('id', $specialtyId)->delete();
        }
    }

    /**
     * S26 §S26.8/§S26.11: RecordEmploymentSpecialtyPeriod's first statement (SELECT ... FOR UPDATE
     * on the relationship) waits behind an in-flight EndEmploymentRelationship's scoped UPDATE, so a
     * specialty can never be recorded against a relationship a concurrent end is about to close.
     */
    public function test_concurrent_specialty_recording_and_employment_end_are_serialised_by_the_relationship_row_lock(): void
    {
        $personId = $this->person();
        $relationshipId = (string) Str::uuid7();
        $this->pg()->insert($this->insertSql(), [$relationshipId, $personId, $this->permanentEmploymentTypeId(), 'PN-SPEC-END-'.Str::random(6), 'PERMANENT', '2026-01-01']);

        $lockSql = 'select id from hr.employment_relationships where id = ? for update';
        $endSql = "update hr.employment_relationships set effective_to = ?, end_knowledge_state = 'KNOWN', version = 2 where id = ? and version = 1 and end_knowledge_state != 'KNOWN'";

        $first = $this->pg();
        $second = $this->second();

        $first->beginTransaction();
        $first->update($endSql, ['2026-06-01', $relationshipId]); // not committed yet

        $second->statement("set lock_timeout = '300ms'");
        $blocked = $this->databaseError(fn () => $second->transaction(fn () => $second->select($lockSql, [$relationshipId])));
        $this->assertTrue(Errors::isLockNotAvailable($blocked), 'the specialty recorder waits on the in-flight end');

        $first->commit();

        $second->transaction(function () use ($second, $relationshipId): void {
            $state = $second->selectOne('select end_knowledge_state from hr.employment_relationships where id = ? for update', [$relationshipId]);
            $this->assertSame('KNOWN', (string) $state->end_knowledge_state, 'the unblocked recorder sees the committed end, never a stale snapshot');
        });

        $this->assertSame(0, (int) $this->scalar('select count(*) from hr.employment_specialty_periods where employment_relationship_id = ?', [$relationshipId]));
    }

    /**
     * S28 (docs/movement-temporal-integrity-corrective-specification.md §S28.10, ADR-S28-001):
     * cross-stream supersession is serialised by the EXISTING EmploymentRelationship row lock —
     * every writer to either movement stream (Start/End secondment, Start/End assignment,
     * TransferEmployee, EndEmploymentRelationship) takes it first. A second session holding the
     * lock with an uncommitted secondment makes StartWorkplaceAssignment wait (lock_timeout
     * fires); once committed, the assignment sees that secondment and SUPERSEDES it instead of
     * overlapping it. The two streams live in two tables, so no single EXCLUDE constraint can span
     * them — the row lock is the (documented) serialisation point.
     */
    public function test_concurrent_cross_stream_starts_are_serialised_and_never_overlap(): void
    {
        $personId = $this->person();
        $relationshipId = (string) Str::uuid7();
        $this->pg()->insert($this->insertSql(), [$relationshipId, $personId, $this->permanentEmploymentTypeId(), 'PN-S28-RACE-'.Str::random(6), 'PERMANENT', '2026-01-01']);
        $secondmentUnit = $this->organizationalUnitId();
        $assignmentUnit = $this->organizationalUnitId();
        $relationship = EmploymentRelationship::query()->findOrFail($relationshipId);
        $unit = OrganizationalUnit::query()->findOrFail($assignmentUnit);
        $assignmentType = DecisionType::query()->where('code', 'ASSIGNMENT')->firstOrFail();

        $second = $this->second();
        $second->beginTransaction();
        $second->select('select id from hr.employment_relationships where id = ? for update', [$relationshipId]);
        $second->insert(
            'insert into hr.full_secondment_periods (id, employment_relationship_id, organizational_unit_id, effective_from, created_at) values (?, ?, ?, ?, now())',
            [(string) Str::uuid7(), $relationshipId, $secondmentUnit, '2026-02-01'],
        ); // an in-flight StartFullSecondment, not committed yet

        DB::statement("set lock_timeout = '300ms'");
        try {
            $blocked = $this->databaseError(fn () => app(StartWorkplaceAssignment::class)->handle($relationship, $unit, '2026-03-01', $assignmentType));
            $this->assertTrue(Errors::isLockNotAvailable($blocked), 'the assignment waits on the in-flight secondment (lock_timeout fired)');
        } finally {
            DB::statement('set lock_timeout = 0');
        }

        $second->commit();

        app(StartWorkplaceAssignment::class)->handle($relationship->refresh(), $unit, '2026-03-01', $assignmentType);

        $this->assertSame('2026-03-01', (string) $this->scalar('select effective_to from hr.full_secondment_periods where employment_relationship_id = ?', [$relationshipId]),
            'the committed secondment is superseded, never overlapped');
        $this->assertSame(0, (int) $this->scalar(<<<'SQL'
            select count(*) from hr.full_secondment_periods s
            join hr.workplace_assignment_periods a on a.employment_relationship_id = s.employment_relationship_id
            where s.employment_relationship_id = ?
              and daterange(s.effective_from, s.effective_to, '[)') && daterange(a.effective_from, a.effective_to, '[)')
            SQL, [$relationshipId]));
    }

    /** Committed directly so the independent second session can see it; removed by the caller. */
    private function jobTitleId(): string
    {
        $id = (string) Str::uuid7();
        DB::table('ref.job_titles')->insert([
            'id' => $id,
            'code' => 's22_race_'.Str::lower(Str::random(8)),
            'name_ar' => 'مسمى اختبار',
            'name_en' => null,
            'is_active' => true,
            'display_order' => 99,
            'version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    /**
     * S23 (docs/person-qualification-foundation-specification.md §S23.8): two real, independent
     * sessions recording the exact same qualification identity for the same Person cannot both
     * commit — person_qualifications_identity_unique (UNIQUE NULLS NOT DISTINCT) makes the second
     * wait, then reject it, with no application-level pre-check involved. The academic degree is
     * committed here (the catalog is deliberately empty) and removed in cleanup.
     */
    public function test_concurrent_duplicate_person_qualifications_are_serialised_and_rejected_by_the_unique_constraint(): void
    {
        $personId = $this->person();
        $degreeId = (string) Str::uuid7();
        DB::table('ref.academic_degrees')->insert([
            'id' => $degreeId, 'code' => 's23_race_'.Str::lower(Str::random(8)), 'name_ar' => 'درجة اختبار', 'name_en' => null,
            'is_active' => true, 'display_order' => 99, 'version' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $insertSql = 'insert into hr.person_qualifications (id, person_id, academic_degree_id, qualification_type_id, created_at) values (?, ?, ?, null, now())';

        $first = $this->pg();
        $second = $this->second();

        try {
            $first->beginTransaction();
            $first->insert($insertSql, [(string) Str::uuid7(), $personId, $degreeId]); // not committed yet

            $second->statement("set lock_timeout = '300ms'");
            $blocked = $this->databaseError(fn () => $second->transaction(fn () => $second->insert($insertSql, [(string) Str::uuid7(), $personId, $degreeId])));
            $this->assertTrue(Errors::isLockNotAvailable($blocked), 'the second session waits on the first (lock_timeout fired)');

            $first->commit();

            $rejected = $this->databaseError(fn () => $second->transaction(fn () => $second->insert($insertSql, [(string) Str::uuid7(), $personId, $degreeId])));
            $this->assertTrue(Errors::isUniqueViolation($rejected), 'once committed, the duplicate is rejected by PostgreSQL itself');

            $this->assertSame(1, (int) $this->scalar('select count(*) from hr.person_qualifications where person_id = ?', [$personId]));
        } finally {
            foreach ([DB::connection(), $this->hasSecond() ? $this->second() : null] as $connection) {
                while ($connection !== null && $connection->transactionLevel() > 0) {
                    $connection->rollBack();
                }
            }
            DB::table('hr.person_qualifications')->where('person_id', $personId)->delete();
            DB::table('ref.academic_degrees')->where('id', $degreeId)->delete();
        }
    }

    /**
     * S29 (docs/work-schedule-foundation-specification.md §S29.11): the database backstop for work
     * schedule periods — two real, independent sessions racing overlapping inserts for the same
     * relationship cannot both commit (write skew is impossible).
     */
    public function test_concurrent_overlapping_work_schedule_periods_for_the_same_relationship_are_serialised_by_the_exclusion_constraint(): void
    {
        $personId = $this->person();
        $relationshipId = (string) Str::uuid7();
        $this->pg()->insert($this->insertSql(), [$relationshipId, $personId, $this->permanentEmploymentTypeId(), 'PN-S29-RACE-'.Str::random(6), 'PERMANENT', '2026-01-01']);

        $periodInsertSql = 'insert into hr.work_schedule_periods (id, employment_relationship_id, effective_from, created_at) values (?, ?, ?, now())';

        $first = $this->pg();
        $second = $this->second();

        $first->beginTransaction();
        $first->insert($periodInsertSql, [(string) Str::uuid7(), $relationshipId, '2026-02-01']); // not committed yet

        $second->statement("set lock_timeout = '300ms'");
        $blocked = $this->databaseError(fn () => $second->transaction(fn () => $second->insert($periodInsertSql, [
            (string) Str::uuid7(), $relationshipId, '2026-03-01',
        ])));
        $this->assertTrue(Errors::isLockNotAvailable($blocked), 'the second session waits on the first (lock_timeout fired)');

        $first->commit();

        $rejected = $this->databaseError(fn () => $second->transaction(fn () => $second->insert($periodInsertSql, [
            (string) Str::uuid7(), $relationshipId, '2026-03-01',
        ])));
        $this->assertTrue(Errors::isExclusionViolation($rejected), 'once committed, the overlap is rejected by PostgreSQL itself');

        $this->assertSame(1, (int) $this->scalar('select count(*) from hr.work_schedule_periods where employment_relationship_id = ?', [$relationshipId]));
    }

    /**
     * S29 §S29.11: RecordWorkSchedulePeriod itself takes the EmploymentRelationship row lock first,
     * so an in-flight concurrent schedule write (holding that lock, not committed) makes it wait;
     * once committed, the command sees that period as the latest and applies the ordinary rules —
     * a later start closes it (adjacent, never overlapping), an earlier or equal start is rejected
     * without a partial write.
     */
    public function test_concurrent_schedule_recordings_are_serialised_by_the_relationship_row_lock(): void
    {
        $personId = $this->person();
        $relationshipId = (string) Str::uuid7();
        $this->pg()->insert($this->insertSql(), [$relationshipId, $personId, $this->permanentEmploymentTypeId(), 'PN-S29-LOCK-'.Str::random(6), 'PERMANENT', '2026-01-01']);
        $relationship = EmploymentRelationship::query()->findOrFail($relationshipId);
        $mondayId = (string) $this->scalar("select id from ref.weekdays where code = 'MONDAY'");
        $inFlightId = (string) Str::uuid7();

        $second = $this->second();
        $second->beginTransaction();
        $second->select('select id from hr.employment_relationships where id = ? for update', [$relationshipId]);
        $second->insert('insert into hr.work_schedule_periods (id, employment_relationship_id, effective_from, created_at) values (?, ?, ?, now())', [$inFlightId, $relationshipId, '2026-03-01']);
        $second->insert('insert into hr.work_schedule_period_weekdays (work_schedule_period_id, weekday_id) values (?, ?)', [$inFlightId, $mondayId]);

        DB::statement("set lock_timeout = '300ms'");
        try {
            $blocked = $this->databaseError(fn () => app(RecordWorkSchedulePeriod::class)->handle($relationship, '2026-02-01', ['TUESDAY']));
            $this->assertTrue(Errors::isLockNotAvailable($blocked), 'the recorder waits on the in-flight schedule write (lock_timeout fired)');
        } finally {
            DB::statement('set lock_timeout = 0');
        }

        $second->commit();

        try {
            app(RecordWorkSchedulePeriod::class)->handle($relationship->refresh(), '2026-02-01', ['TUESDAY']);
            $this->fail('an earlier start than the committed concurrent period is rejected');
        } catch (InvalidWorkSchedulePeriodDateException) {
        }
        $this->assertNull($this->scalar('select effective_to from hr.work_schedule_periods where id = ?', [$inFlightId]), 'no partial closure');

        app(RecordWorkSchedulePeriod::class)->handle($relationship->refresh(), '2026-05-01', ['TUESDAY']);

        $this->assertSame('2026-05-01', (string) $this->scalar('select effective_to from hr.work_schedule_periods where id = ?', [$inFlightId]));
        $this->assertSame(2, (int) $this->scalar('select count(*) from hr.work_schedule_periods where employment_relationship_id = ?', [$relationshipId]));
    }

    /**
     * S29 §S29.10/§S29.11: a schedule can never be recorded against a relationship a concurrent end
     * is about to close — RecordWorkSchedulePeriod waits behind the in-flight end's row lock and,
     * once unblocked, sees the committed KNOWN end and rejects (409).
     */
    public function test_concurrent_schedule_recording_and_employment_end_are_serialised_by_the_relationship_row_lock(): void
    {
        $personId = $this->person();
        $relationshipId = (string) Str::uuid7();
        $this->pg()->insert($this->insertSql(), [$relationshipId, $personId, $this->permanentEmploymentTypeId(), 'PN-S29-END-'.Str::random(6), 'PERMANENT', '2026-01-01']);
        $relationship = EmploymentRelationship::query()->findOrFail($relationshipId);

        $second = $this->second();
        $second->beginTransaction();
        $second->update("update hr.employment_relationships set effective_to = ?, end_knowledge_state = 'KNOWN', version = 2 where id = ? and version = 1 and end_knowledge_state != 'KNOWN'", ['2026-06-01', $relationshipId]);

        DB::statement("set lock_timeout = '300ms'");
        try {
            $blocked = $this->databaseError(fn () => app(RecordWorkSchedulePeriod::class)->handle($relationship, '2026-03-01', ['MONDAY']));
            $this->assertTrue(Errors::isLockNotAvailable($blocked), 'the recorder waits on the in-flight end');
        } finally {
            DB::statement('set lock_timeout = 0');
        }

        $second->commit();

        try {
            app(RecordWorkSchedulePeriod::class)->handle($relationship, '2026-03-01', ['MONDAY']);
            $this->fail('the unblocked recorder sees the committed end, never a stale snapshot');
        } catch (EmploymentRelationshipAlreadyEndedException) {
        }

        $this->assertSame(0, (int) $this->scalar('select count(*) from hr.work_schedule_periods where employment_relationship_id = ?', [$relationshipId]));
    }

    /** Inserts a partial secondment period and its weekday membership on the given session (uncommitted unless the caller commits). */
    private function insertPartialOn(Connection $connection, string $relationshipId, string $unitId, string $from, array $codes): string
    {
        $id = (string) Str::uuid7();
        $connection->insert(
            'insert into hr.partial_secondment_periods (id, employment_relationship_id, organizational_unit_id, effective_from, created_at) values (?, ?, ?, ?, now())',
            [$id, $relationshipId, $unitId, $from],
        );
        foreach ($codes as $code) {
            $connection->insert(
                'insert into hr.partial_secondment_period_weekdays (partial_secondment_period_id, weekday_id, employment_relationship_id, period)
                 select p.id, w.id, p.employment_relationship_id, p.period from hr.partial_secondment_periods p, ref.weekdays w where p.id = ? and w.code = ?',
                [$id, $code],
            );
        }

        return $id;
    }

    /**
     * S30 AJ (docs/partial-secondment-foundation-specification.md §S30.22): the database backstop
     * for weekday allocation — two real, independent sessions racing overlapping Partial
     * Secondments that SHARE a weekday cannot both commit; the weekday-level EXCLUDE makes the
     * second wait, then rejects it once the first commits.
     */
    public function test_concurrent_partial_secondments_sharing_a_weekday_are_serialised_by_the_exclusion_constraint(): void
    {
        $personId = $this->person();
        $relationshipId = (string) Str::uuid7();
        $this->pg()->insert($this->insertSql(), [$relationshipId, $personId, $this->permanentEmploymentTypeId(), 'PN-S30-RACE-'.Str::random(6), 'PERMANENT', '2026-01-01']);
        $unitA = $this->organizationalUnitId();
        $unitB = $this->organizationalUnitId();

        $first = $this->pg();
        $second = $this->second();

        $first->beginTransaction();
        $this->insertPartialOn($first, $relationshipId, $unitA, '2026-03-01', ['SUNDAY', 'MONDAY']); // not committed yet

        $second->statement("set lock_timeout = '300ms'");
        $blocked = $this->databaseError(fn () => $second->transaction(fn () => $this->insertPartialOn($second, $relationshipId, $unitB, '2026-04-01', ['MONDAY', 'TUESDAY'])));
        $this->assertTrue(Errors::isLockNotAvailable($blocked), 'the second session waits on the first (lock_timeout fired)');

        $first->commit();

        $rejected = $this->databaseError(fn () => $second->transaction(fn () => $this->insertPartialOn($second, $relationshipId, $unitB, '2026-04-01', ['MONDAY', 'TUESDAY'])));
        $this->assertTrue(Errors::isExclusionViolation($rejected), 'once committed, the shared MONDAY is rejected by PostgreSQL itself');

        $this->assertSame(1, (int) $this->scalar('select count(*) from hr.partial_secondment_periods where employment_relationship_id = ?', [$relationshipId]));
    }

    /**
     * S30 AK: disjoint weekdays are valid allocation, so two concurrent sessions allocating
     * disjoint weekdays on overlapping dates both commit — the exclusion constraint never blocks
     * them against each other.
     */
    public function test_concurrent_partial_secondments_on_disjoint_weekdays_both_commit(): void
    {
        $personId = $this->person();
        $relationshipId = (string) Str::uuid7();
        $this->pg()->insert($this->insertSql(), [$relationshipId, $personId, $this->permanentEmploymentTypeId(), 'PN-S30-DISJ-'.Str::random(6), 'PERMANENT', '2026-01-01']);
        $unitA = $this->organizationalUnitId();
        $unitB = $this->organizationalUnitId();

        $first = $this->pg();
        $second = $this->second();

        $first->beginTransaction();
        $this->insertPartialOn($first, $relationshipId, $unitA, '2026-03-01', ['SUNDAY', 'MONDAY']); // not committed yet

        $second->statement("set lock_timeout = '300ms'");
        $second->transaction(fn () => $this->insertPartialOn($second, $relationshipId, $unitB, '2026-03-01', ['TUESDAY', 'WEDNESDAY']));

        $first->commit();

        $this->assertSame(2, (int) $this->scalar('select count(*) from hr.partial_secondment_periods where employment_relationship_id = ?', [$relationshipId]));
    }

    /**
     * S30 ADR-S30-007 #9: Assignment ↔ Partial starts are serialised by the EmploymentRelationship
     * row lock (the S28 discipline — the two streams live in two tables, so no single EXCLUDE can
     * span them). A second session holding the lock with an uncommitted Partial Secondment makes
     * StartWorkplaceAssignment wait; once committed, the assignment sees it and SUPERSEDES it
     * (truncation at D) — the two never remain effective together.
     */
    public function test_concurrent_assignment_and_partial_starts_are_serialised_and_never_overlap(): void
    {
        $personId = $this->person();
        $relationshipId = (string) Str::uuid7();
        $this->pg()->insert($this->insertSql(), [$relationshipId, $personId, $this->permanentEmploymentTypeId(), 'PN-S30-ASG-'.Str::random(6), 'PERMANENT', '2026-01-01']);
        $partialUnit = $this->organizationalUnitId();
        $relationship = EmploymentRelationship::query()->findOrFail($relationshipId);
        $unit = OrganizationalUnit::query()->findOrFail($this->organizationalUnitId());
        $assignmentType = DecisionType::query()->where('code', 'ASSIGNMENT')->firstOrFail();

        $second = $this->second();
        $second->beginTransaction();
        $second->select('select id from hr.employment_relationships where id = ? for update', [$relationshipId]);
        $partialId = $this->insertPartialOn($second, $relationshipId, $partialUnit, '2026-02-01', ['MONDAY']); // an in-flight RecordPartialSecondmentPeriod

        DB::statement("set lock_timeout = '300ms'");
        try {
            $blocked = $this->databaseError(fn () => app(StartWorkplaceAssignment::class)->handle($relationship, $unit, '2026-03-01', $assignmentType));
            $this->assertTrue(Errors::isLockNotAvailable($blocked), 'the assignment waits on the in-flight partial secondment');
        } finally {
            DB::statement('set lock_timeout = 0');
        }

        $second->commit();

        app(StartWorkplaceAssignment::class)->handle($relationship->refresh(), $unit, '2026-03-01', $assignmentType);

        $this->assertSame('2026-03-01', (string) $this->scalar('select effective_to from hr.partial_secondment_periods where id = ?', [$partialId]), 'superseded, not overlapped');
        $this->assertSame('[2026-02-01,2026-03-01)', (string) $this->scalar('select period from hr.partial_secondment_period_weekdays where partial_secondment_period_id = ?', [$partialId]));
        $this->assertSame(0, (int) $this->scalar(
            "select count(*) from hr.partial_secondment_periods p join hr.workplace_assignment_periods a on a.employment_relationship_id = p.employment_relationship_id
             where p.employment_relationship_id = ? and daterange(p.effective_from, p.effective_to, '[)') && daterange(a.effective_from, a.effective_to, '[)')",
            [$relationshipId],
        ), 'no effective overlap between assignment and partial secondment');
    }

    /** A committed relationship, destination unit and bounded Full Secondment [03-01, 05-01) for S31 races. */
    private function boundedFullSecondment(string $numberPrefix): array
    {
        $relationshipId = (string) Str::uuid7();
        $this->pg()->insert($this->insertSql(), [$relationshipId, $this->person(), $this->permanentEmploymentTypeId(), $numberPrefix.Str::random(6), 'PERMANENT', '2026-01-01']);
        $unitId = $this->organizationalUnitId();
        $movementId = (string) Str::uuid7();
        $this->pg()->insert(
            'insert into hr.full_secondment_periods (id, employment_relationship_id, organizational_unit_id, effective_from, effective_to, created_at) values (?, ?, ?, ?, ?, now())',
            [$movementId, $relationshipId, $unitId, '2026-03-01', '2026-05-01'],
        );

        return [$relationshipId, $movementId];
    }

    private function followUpEmissionSql(): string
    {
        return "INSERT INTO automation.movement_expiry_followups
                    (id, followup_kind, movement_type, full_secondment_period_id, employment_relationship_id, organizational_unit_id,
                     expected_effective_to, due_date, status, created_at)
                SELECT ?, 'EXPIRY_WARNING_7D', 'FULL_SECONDMENT', m.id, m.employment_relationship_id, m.organizational_unit_id,
                       m.effective_to, m.effective_to - 7, 'ACTIONABLE', now()
                FROM hr.full_secondment_periods m WHERE m.id = ? AND m.effective_to = CAST(? AS date)
                ON CONFLICT ON CONSTRAINT movement_expiry_followups_logical_key DO NOTHING
                RETURNING id";
    }

    /**
     * S31 K (docs/movement-expiry-followup-foundation-specification.md §S31.10, ADR-S31-007): the
     * SAME logical follow-up discovered concurrently by two real, independent sessions yields
     * exactly ONE durable row. PostgreSQL's logical-identity UNIQUE constraint is the backstop: the
     * second session's INSERT … ON CONFLICT DO NOTHING waits for the first (lock_timeout fires) and,
     * once the first commits, becomes a no-op — no duplicate, no error, no application-only guard.
     */
    public function test_concurrent_scans_of_the_same_logical_follow_up_produce_exactly_one_row(): void
    {
        [$relationshipId, $movementId] = $this->boundedFullSecondment('PN-S31-KEY-');

        $first = $this->pg();
        $second = $this->second();

        $first->beginTransaction();
        $this->assertCount(1, $first->select($this->followUpEmissionSql(), [(string) Str::uuid7(), $movementId, '2026-05-01'])); // not committed yet

        $second->statement("set lock_timeout = '300ms'");
        $blocked = $this->databaseError(fn () => $second->transaction(fn () => $second->select($this->followUpEmissionSql(), [(string) Str::uuid7(), $movementId, '2026-05-01'])));
        $this->assertTrue(Errors::isLockNotAvailable($blocked), 'the concurrent scan waits on the in-flight identical insert (lock_timeout fired)');

        $first->commit();

        $this->assertCount(0, $second->transaction(fn () => $second->select($this->followUpEmissionSql(), [(string) Str::uuid7(), $movementId, '2026-05-01'])), 'once committed, the same logical key is a silent no-op');

        $this->assertSame(1, (int) $this->scalar('select count(*) from automation.movement_expiry_followups where employment_relationship_id = ?', [$relationshipId]));
    }

    /**
     * S31 K (distinct identities): a DIFFERENT logical follow-up (another expected end for the same
     * movement) is not blocked by, and does not conflict with, the first — identity is per
     * (kind, movement, expected end), not per movement.
     */
    public function test_concurrent_follow_ups_with_different_expected_ends_are_independent(): void
    {
        [$relationshipId, $movementId] = $this->boundedFullSecondment('PN-S31-IND-');
        $this->pg()->update('update hr.full_secondment_periods set effective_to = ? where id = ?', ['2026-05-08', $movementId]);

        $first = $this->pg();
        $second = $this->second();

        $first->beginTransaction();
        $this->assertCount(1, $first->select($this->followUpEmissionSql(), [(string) Str::uuid7(), $movementId, '2026-05-08']));

        // A stale key for the same movement (expected end 05-01, which no longer matches) inserts nothing.
        $second->statement("set lock_timeout = '300ms'");
        $this->assertCount(0, $second->transaction(fn () => $second->select($this->followUpEmissionSql(), [(string) Str::uuid7(), $movementId, '2026-05-01'])), 'the INSERT … SELECT only copies the CURRENT end date: a stale expected end yields nothing');

        $first->commit();
        $this->assertSame(1, (int) $this->scalar('select count(*) from automation.movement_expiry_followups where employment_relationship_id = ?', [$relationshipId]));
    }

    /**
     * S31 ADR-S31-005 under a REAL race: the scanner takes the EmploymentRelationship row lock like
     * every movement command, so a movement command in flight (holding the lock with an uncommitted
     * truncation) makes the emission WAIT; once it commits, the emission reloads the authoritative
     * movement, sees the new end date and is suppressed — a stale follow-up is never emitted and
     * nothing is written.
     */
    public function test_an_in_flight_movement_change_makes_the_scanner_wait_and_then_recheck_stale(): void
    {
        [$relationshipId, $movementId] = $this->boundedFullSecondment('PN-S31-RECHK-');
        $context = new CommandContext(Actor::system(ScanMovementExpiryFollowUps::ACTOR_LABEL), CorrelationId::generate(), Source::System);
        $emit = fn () => app(ScanMovementExpiryFollowUps::class)->emit(TemporaryMovementType::FullSecondment, $movementId, $relationshipId, '2026-05-01', '2026-04-25', $context);

        $second = $this->second();
        $second->beginTransaction();
        $second->select('select id from hr.employment_relationships where id = ? for update', [$relationshipId]);
        $second->update('update hr.full_secondment_periods set effective_to = ? where id = ?', ['2026-04-27', $movementId]); // an in-flight movement command, not committed yet

        DB::statement("set lock_timeout = '300ms'");
        try {
            $blocked = $this->databaseError($emit);
            $this->assertTrue(Errors::isLockNotAvailable($blocked), 'the scanner waits on the in-flight movement command');
        } finally {
            DB::statement('set lock_timeout = 0');
        }

        $second->commit();

        $emission = $emit();

        $this->assertSame(ExpiryFollowUpEmission::STALE, $emission->outcome);
        $this->assertSame(FollowUpSuppressionReason::TruncatedEarlier, $emission->reason);
        $this->assertSame(0, (int) $this->scalar('select count(*) from automation.movement_expiry_followups where employment_relationship_id = ?', [$relationshipId]), 'nothing was emitted for the stale expected end');
    }

    /**
     * S32 (ADR-S32-016): bounded status periods use the same PostgreSQL EXCLUDE as open-ended ones,
     * so two concurrent bounded periods overlapping for one relationship are serialised and the
     * loser is rejected by the database itself, not merely delayed.
     */
    public function test_concurrent_overlapping_bounded_status_periods_are_serialised_by_the_exclusion_constraint(): void
    {
        $personId = $this->person();
        $relationshipId = (string) Str::uuid7();
        $this->pg()->insert($this->insertSql(), [$relationshipId, $personId, $this->permanentEmploymentTypeId(), 'PN-S32-BOUNDED-RACE', 'PERMANENT', '2026-01-01']);

        $leaveId = $this->statusDetailId('unpaid_leave');
        $sickId = $this->statusDetailId('external_sick_leave');
        $sql = <<<'SQL'
            insert into hr.employment_status_periods
                (id, employment_relationship_id, status_detail_id, effective_from, effective_to, created_at)
            values (?, ?, ?, ?, ?, now())
            SQL;

        $first = $this->pg();
        $second = $this->second();

        $first->beginTransaction();
        $first->insert($sql, [(string) Str::uuid7(), $relationshipId, $leaveId, '2026-10-01', '2026-11-01']);

        $second->statement("set lock_timeout = '300ms'");
        $blocked = $this->databaseError(fn () => $second->transaction(fn () => $second->insert($sql, [
            (string) Str::uuid7(), $relationshipId, $sickId, '2026-10-15', '2026-11-15',
        ])));
        $this->assertTrue(Errors::isLockNotAvailable($blocked), 'the second bounded insert waits on the first');

        $first->commit();

        $rejected = $this->databaseError(fn () => $second->transaction(fn () => $second->insert($sql, [
            (string) Str::uuid7(), $relationshipId, $sickId, '2026-10-15', '2026-11-15',
        ])));
        $this->assertTrue(Errors::isExclusionViolation($rejected));

        // Adjacent half-open periods do not overlap: [11-01, 12-01) is accepted after [10-01, 11-01).
        $second->transaction(fn () => $second->insert($sql, [(string) Str::uuid7(), $relationshipId, $sickId, '2026-11-01', '2026-12-01']));

        $this->assertSame(2, (int) $this->scalar('select count(*) from hr.employment_status_periods where employment_relationship_id = ?', [$relationshipId]));
    }

    /** Runs $work with the given connection as the application's default (so real Eloquent commands use that session). */
    private function asSession(string $connectionName, callable $work): mixed
    {
        $original = config('database.default');
        DB::setDefaultConnection($connectionName);
        try {
            return $work();
        } finally {
            DB::setDefaultConnection($original);
        }
    }

    private function s32Relationship(): array
    {
        $personId = $this->person();
        $relationshipId = (string) Str::uuid7();
        $this->pg()->insert($this->insertSql(), [$relationshipId, $personId, $this->permanentEmploymentTypeId(), 'PN-S32-RACE-'.Str::random(6), 'PERMANENT', '2026-01-01']);

        return [$personId, $relationshipId];
    }

    private function recordBounded(string $personId, string $relationshipId, string $code, string $from, ?string $to): mixed
    {
        return DB::transaction(fn () => app(RecordEmploymentStatusPeriod::class)->handle(
            Person::query()->findOrFail($personId),
            EmploymentRelationship::query()->findOrFail($relationshipId),
            EmploymentStatusDetail::query()->where('code', $code)->firstOrFail(),
            $from,
            $to,
        ));
    }

    private function endRelationship(string $personId, string $relationshipId, string $to): mixed
    {
        // Same transaction ownership as the production controller: the caller wraps the command.
        return DB::transaction(function () use ($personId, $relationshipId, $to) {
            $relationship = EmploymentRelationship::query()->findOrFail($relationshipId);

            return app(EndEmploymentRelationship::class)->handle(Person::query()->findOrFail($personId), $relationship, $relationship->version, $to, false);
        });
    }

    /** S32 invariant: no committed status period violates the final known relationship bounds, and none overlap. */
    private function assertNoStatusPeriodViolatesRelationshipBounds(string $relationshipId): void
    {
        $violations = (int) $this->scalar(<<<'SQL'
            select count(*) from hr.employment_status_periods p
            join hr.employment_relationships r on r.id = p.employment_relationship_id
            where r.id = ? and r.end_knowledge_state = 'KNOWN' and (
                (p.effective_from >= r.effective_to and not (p.effective_to is null and p.effective_from = r.effective_to))
                or (p.effective_from < r.effective_to and (p.effective_to is null or p.effective_to > r.effective_to))
            )
            SQL, [$relationshipId]);
        $this->assertSame(0, $violations, 'a committed status period extends beyond the relationship end');

        $overlaps = (int) $this->scalar(<<<'SQL'
            select count(*) from hr.employment_status_periods a
            join hr.employment_status_periods b on a.employment_relationship_id = b.employment_relationship_id and a.id < b.id
            where a.employment_relationship_id = ?
              and daterange(a.effective_from, a.effective_to, '[)') && daterange(b.effective_from, b.effective_to, '[)')
            SQL, [$relationshipId]);
        $this->assertSame(0, $overlaps);
    }

    /**
     * S32 gate (real production paths, real two sessions): RecordEmploymentStatusPeriod (session A,
     * bounded status, in flight and uncommitted inside its own DB::transaction) races
     * EndEmploymentRelationship (session B, its own DB::transaction as the controller does). The end
     * blocks on the relationship row lock (lock_timeout fires deterministically, no sleeps), then after
     * A commits it re-validates against the committed bounded period and truncates it at the end date.
     */
    public function test_s32_status_first_then_relationship_end_revalidates_and_truncates_the_committed_bounded_status(): void
    {
        [$personId, $relationshipId] = $this->s32Relationship();
        $this->second(); // register the second session's config
        $original = config('database.default');

        // Session A: the real command runs to completion inside an open transaction that we hold.
        DB::beginTransaction();
        $period = $this->recordBounded($personId, $relationshipId, 'unpaid_leave', '2026-10-01', '2026-12-01');
        $this->assertNotNull($period->id);

        // Session B: the real end command must wait behind A's relationship row lock.
        $blocked = $this->asSession(self::SECOND, function () use ($personId, $relationshipId) {
            DB::statement("set lock_timeout = '300ms'");
            try {
                return $this->databaseError(fn () => $this->endRelationship($personId, $relationshipId, '2026-11-01'));
            } finally {
                DB::statement('set lock_timeout = 0');
            }
        });
        $this->assertTrue(Errors::isLockNotAvailable($blocked), 'EndEmploymentRelationship waits on the in-flight bounded-status command');
        $this->assertSame($original, config('database.default'));

        DB::commit();

        $this->asSession(self::SECOND, fn () => $this->endRelationship($personId, $relationshipId, '2026-11-01'));

        $this->assertSame('2026-11-01', (string) $this->scalar('select effective_to from hr.employment_status_periods where id = ?', [$period->id]), 'truncated at the end date');
        $this->assertSame('KNOWN', (string) $this->scalar('select end_knowledge_state from hr.employment_relationships where id = ?', [$relationshipId]));
        $this->assertNoStatusPeriodViolatesRelationshipBounds($relationshipId);
    }

    /** Same race, but the committed bounded status starts on/after the requested end: the end is rejected atomically. */
    public function test_s32_status_first_then_a_relationship_end_before_the_status_start_is_rejected_atomically(): void
    {
        [$personId, $relationshipId] = $this->s32Relationship();
        $this->second();

        DB::beginTransaction();
        $period = $this->recordBounded($personId, $relationshipId, 'external_sick_leave', '2026-12-01', '2027-01-01');

        $blocked = $this->asSession(self::SECOND, function () use ($personId, $relationshipId) {
            DB::statement("set lock_timeout = '300ms'");
            try {
                return $this->databaseError(fn () => $this->endRelationship($personId, $relationshipId, '2026-11-01'));
            } finally {
                DB::statement('set lock_timeout = 0');
            }
        });
        $this->assertTrue(Errors::isLockNotAvailable($blocked));

        DB::commit();

        $rejected = $this->asSession(self::SECOND, fn () => $this->databaseError(fn () => $this->endRelationship($personId, $relationshipId, '2026-11-01')));
        $this->assertInstanceOf(InvalidEndDateException::class, $rejected);

        $this->assertSame('NOT_APPLICABLE', (string) $this->scalar('select end_knowledge_state from hr.employment_relationships where id = ?', [$relationshipId]), 'no partial relationship-end mutation');
        $this->assertNull($this->scalar('select effective_to from hr.employment_relationships where id = ?', [$relationshipId]));
        $this->assertSame('2027-01-01', (string) $this->scalar('select effective_to from hr.employment_status_periods where id = ?', [$period->id]), 'status untouched');
        $this->assertNoStatusPeriodViolatesRelationshipBounds($relationshipId);
    }

    /**
     * Inverse ordering: the real end command (session B) is in flight and uncommitted; the real
     * bounded-status command (session A) blocks, then re-reads the committed KNOWN end and is rejected
     * (409) without writing anything.
     */
    public function test_s32_end_first_then_bounded_status_is_rejected_after_the_committed_end(): void
    {
        [$personId, $relationshipId] = $this->s32Relationship();
        $this->second();
        $original = config('database.default');

        $this->asSession(self::SECOND, function () use ($personId, $relationshipId) {
            DB::beginTransaction();
            $this->endRelationship($personId, $relationshipId, '2026-11-01'); // nested savepoint: still uncommitted
            $this->assertSame(1, DB::transactionLevel());
        });

        $blocked = $this->databaseError(function () use ($personId, $relationshipId) {
            DB::statement("set lock_timeout = '300ms'");
            try {
                $this->recordBounded($personId, $relationshipId, 'unpaid_leave', '2026-10-01', '2026-12-01');
            } finally {
                DB::statement('set lock_timeout = 0');
            }
        });
        $this->assertTrue(Errors::isLockNotAvailable($blocked), 'the bounded-status command waits on the in-flight end');

        $this->asSession(self::SECOND, fn () => DB::commit());
        $this->assertSame($original, config('database.default'));

        $rejected = $this->databaseError(fn () => $this->recordBounded($personId, $relationshipId, 'unpaid_leave', '2026-10-01', '2026-12-01'));
        $this->assertInstanceOf(EmploymentRelationshipAlreadyEndedException::class, $rejected);

        $this->assertSame(0, (int) $this->scalar('select count(*) from hr.employment_status_periods where employment_relationship_id = ?', [$relationshipId]));
        $this->assertSame('KNOWN', (string) $this->scalar('select end_knowledge_state from hr.employment_relationships where id = ?', [$relationshipId]));
        $this->assertNoStatusPeriodViolatesRelationshipBounds($relationshipId);
    }

    /** Inverse ordering where the end is in flight and the (still-valid-looking) bounded status fully precedes it. */
    public function test_s32_end_first_then_bounded_status_ending_before_the_end_date_is_still_rejected_because_the_relationship_is_ended(): void
    {
        [$personId, $relationshipId] = $this->s32Relationship();
        $this->second();

        $this->asSession(self::SECOND, function () use ($personId, $relationshipId) {
            DB::beginTransaction();
            $this->endRelationship($personId, $relationshipId, '2026-12-01');
        });

        $blocked = $this->databaseError(function () use ($personId, $relationshipId) {
            DB::statement("set lock_timeout = '300ms'");
            try {
                $this->recordBounded($personId, $relationshipId, 'traveling', '2026-10-01', '2026-11-01');
            } finally {
                DB::statement('set lock_timeout = 0');
            }
        });
        $this->assertTrue(Errors::isLockNotAvailable($blocked));

        $this->asSession(self::SECOND, fn () => DB::commit());

        $rejected = $this->databaseError(fn () => $this->recordBounded($personId, $relationshipId, 'traveling', '2026-10-01', '2026-11-01'));
        $this->assertInstanceOf(EmploymentRelationshipAlreadyEndedException::class, $rejected);
        $this->assertSame(0, (int) $this->scalar('select count(*) from hr.employment_status_periods where employment_relationship_id = ?', [$relationshipId]));
        $this->assertNoStatusPeriodViolatesRelationshipBounds($relationshipId);
    }

    private function recordIntention(string $relationshipId, string $intention, string $from, ?string $to = null): mixed
    {
        return DB::transaction(fn () => app(RecordReturnIntention::class)->handle(
            EmploymentRelationship::query()->findOrFail($relationshipId), $intention, $from, $to,
        ));
    }

    private function blockedWithinLockTimeout(callable $work): \Throwable
    {
        DB::statement("set lock_timeout = '300ms'");
        try {
            return $this->databaseError($work);
        } finally {
            DB::statement('set lock_timeout = 0');
        }
    }

    /**
     * S34: two real RecordReturnIntention commands on two sessions. The second blocks behind the first's
     * relationship row lock (deterministic lock_timeout, no sleeps); once the first commits, the second
     * re-reads committed state: a same-start write is rejected (never a silent rewrite) and a later start
     * truncates the committed period. The PostgreSQL EXCLUDE is the backstop for raw overlapping inserts.
     */
    public function test_s34_concurrent_return_intention_writes_are_serialised_and_never_overlap(): void
    {
        [$personId, $relationshipId] = $this->s32Relationship();
        $this->second();

        DB::beginTransaction();
        $this->recordIntention($relationshipId, 'WANTS_TO_RETURN', '2026-10-01');

        $blocked = $this->asSession(self::SECOND, fn () => $this->blockedWithinLockTimeout(fn () => $this->recordIntention($relationshipId, 'DOES_NOT_WANT_TO_RETURN', '2026-10-01')));
        $this->assertTrue(Errors::isLockNotAvailable($blocked), 'the second intention writer waits on the first');

        DB::commit();

        $rejected = $this->asSession(self::SECOND, fn () => $this->databaseError(fn () => $this->recordIntention($relationshipId, 'DOES_NOT_WANT_TO_RETURN', '2026-10-01')));
        $this->assertInstanceOf(InvalidReturnIntentionPeriodDateException::class, $rejected);

        $this->asSession(self::SECOND, fn () => $this->recordIntention($relationshipId, 'DOES_NOT_WANT_TO_RETURN', '2026-11-01'));
        $rows = DB::table('hr.return_intention_periods')->where('employment_relationship_id', $relationshipId)->orderBy('effective_from')->get(['intention', 'effective_from', 'effective_to']);
        $this->assertSame(['WANTS_TO_RETURN', '2026-10-01', '2026-11-01'], [$rows[0]->intention, $rows[0]->effective_from, $rows[0]->effective_to]);
        $this->assertSame(['DOES_NOT_WANT_TO_RETURN', '2026-11-01', null], [$rows[1]->intention, $rows[1]->effective_from, $rows[1]->effective_to]);

        $overlap = $this->databaseError(fn () => DB::transaction(fn () => DB::table('hr.return_intention_periods')->insert([
            'id' => (string) Str::uuid7(), 'employment_relationship_id' => $relationshipId, 'intention' => 'WANTS_TO_RETURN',
            'effective_from' => '2026-10-15', 'effective_to' => '2026-11-15', 'created_at' => now(),
        ])));
        $this->assertTrue(Errors::isExclusionViolation($overlap));
    }

    /**
     * S34: relationship end vs intention, real commands, both orders. Intention first: the end waits, then
     * truncates the committed period. End first: the intention waits, then is rejected on the ended
     * relationship (409) and writes nothing. In both orders no period extends beyond the final end.
     */
    public function test_s34_relationship_end_and_return_intention_are_serialised_in_both_orders(): void
    {
        // Order 1: intention in flight, end second.
        [$personId, $relationshipId] = $this->s32Relationship();
        $this->second();
        DB::beginTransaction();
        $this->recordIntention($relationshipId, 'WANTS_TO_RETURN', '2026-10-01', '2026-12-01');
        $blocked = $this->asSession(self::SECOND, fn () => $this->blockedWithinLockTimeout(fn () => $this->endRelationship($personId, $relationshipId, '2026-11-01')));
        $this->assertTrue(Errors::isLockNotAvailable($blocked), 'end waits on the in-flight intention write');
        DB::commit();
        $this->asSession(self::SECOND, fn () => $this->endRelationship($personId, $relationshipId, '2026-11-01'));
        $this->assertSame('2026-11-01', (string) $this->scalar('select effective_to from hr.return_intention_periods where employment_relationship_id = ?', [$relationshipId]));
        $this->assertSame(0, (int) $this->scalar('select count(*) from hr.return_intention_periods i join hr.employment_relationships r on r.id = i.employment_relationship_id where r.id = ? and (i.effective_to is null or i.effective_to > r.effective_to)', [$relationshipId]));

        // Order 2: end in flight, intention second.
        [$personId2, $relationshipId2] = $this->s32Relationship();
        $this->asSession(self::SECOND, function () use ($personId2, $relationshipId2) {
            DB::beginTransaction();
            $this->endRelationship($personId2, $relationshipId2, '2026-11-01');
        });
        $blocked = $this->blockedWithinLockTimeout(fn () => $this->recordIntention($relationshipId2, 'WANTS_TO_RETURN', '2026-10-01'));
        $this->assertTrue(Errors::isLockNotAvailable($blocked), 'intention waits on the in-flight end');
        $this->asSession(self::SECOND, fn () => DB::commit());
        $rejected = $this->databaseError(fn () => $this->recordIntention($relationshipId2, 'WANTS_TO_RETURN', '2026-10-01'));
        $this->assertInstanceOf(EmploymentRelationshipAlreadyEndedException::class, $rejected);
        $this->assertSame(0, (int) $this->scalar('select count(*) from hr.return_intention_periods where employment_relationship_id = ?', [$relationshipId2]));
    }

    /**
     * S34: status vs intention. The streams are logically independent and share no constraint; the only
     * coupling is the relationship row lock every temporal writer takes (needed for end-of-relationship
     * integrity). An in-flight status write makes the intention writer wait; once committed, both
     * commit and neither disturbs the other's rows.
     */
    public function test_s34_status_and_return_intention_writers_are_independent_but_share_the_relationship_lock(): void
    {
        [$personId, $relationshipId] = $this->s32Relationship();
        $this->second();

        DB::beginTransaction();
        $this->recordBounded($personId, $relationshipId, 'unpaid_leave', '2026-10-01', '2026-12-01');
        $blocked = $this->asSession(self::SECOND, fn () => $this->blockedWithinLockTimeout(fn () => $this->recordIntention($relationshipId, 'WANTS_TO_RETURN', '2026-10-05')));
        $this->assertTrue(Errors::isLockNotAvailable($blocked), 'intention waits on the in-flight status write (relationship integrity lock)');
        DB::commit();

        $this->asSession(self::SECOND, fn () => $this->recordIntention($relationshipId, 'WANTS_TO_RETURN', '2026-10-05'));

        $this->assertSame(1, (int) $this->scalar('select count(*) from hr.employment_status_periods where employment_relationship_id = ?', [$relationshipId]));
        $this->assertSame('2026-12-01', (string) $this->scalar('select effective_to from hr.employment_status_periods where employment_relationship_id = ?', [$relationshipId]), 'status untouched by the intention');
        $this->assertSame(1, (int) $this->scalar('select count(*) from hr.return_intention_periods where employment_relationship_id = ?', [$relationshipId]));
    }
}
