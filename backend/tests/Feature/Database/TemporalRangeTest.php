<?php

namespace Tests\Feature\Database;

use App\Modules\Platform\Infrastructure\Persistence\Postgres\PostgresErrorClassifier as Errors;
use App\Modules\Platform\Infrastructure\Persistence\Postgres\TemporalConstraints as Temporal;
use Illuminate\Support\Str;
use Tests\PostgresIntegrationTestCase;

/**
 * Proves the [from, to) half-open convention and the GiST exclusion pattern later domain stages will reuse.
 * All structures are TEMPORARY tables created inside a transaction that is rolled back: nothing persists.
 */
class TemporalRangeTest extends PostgresIntegrationTestCase
{
    private const RANGE = "daterange(?::date, ?::date, '[)')";

    protected function setUp(): void
    {
        parent::setUp();

        $this->pg()->beginTransaction();
    }

    protected function tearDown(): void
    {
        while ($this->pg()->transactionLevel() > 0) {
            $this->pg()->rollBack();
        }

        parent::tearDown();
    }

    private function overlaps(string $aFrom, ?string $aTo, string $bFrom, ?string $bTo): bool
    {
        return (bool) $this->scalar(
            'select '.self::RANGE.' && '.self::RANGE,
            [$aFrom, $aTo, $bFrom, $bTo],
        );
    }

    // ---- canonical range semantics -------------------------------------------------------------------------

    public function test_the_lower_bound_is_inclusive_and_the_upper_bound_is_exclusive(): void
    {
        $range = "daterange('2026-01-01', '2026-02-01', '[)')";

        $this->assertTrue((bool) $this->scalar("select {$range} @> '2026-01-01'::date"), 'effective_from is inclusive');
        $this->assertTrue((bool) $this->scalar("select {$range} @> '2026-01-31'::date"));
        $this->assertFalse((bool) $this->scalar("select {$range} @> '2026-02-01'::date"), 'effective_to is exclusive');
        $this->assertTrue((bool) $this->scalar("select lower_inc({$range})"));
        $this->assertFalse((bool) $this->scalar("select upper_inc({$range})"));
    }

    public function test_adjacent_periods_do_not_overlap(): void
    {
        $this->assertFalse($this->overlaps('2026-01-01', '2026-02-01', '2026-02-01', '2026-03-01'));
        $this->assertFalse($this->overlaps('2026-02-01', '2026-03-01', '2026-01-01', '2026-02-01'));
        $this->assertTrue((bool) $this->scalar('select '.self::RANGE.' -|- '.self::RANGE, ['2026-01-01', '2026-02-01', '2026-02-01', '2026-03-01']));
    }

    public function test_intersecting_periods_overlap_even_by_a_single_day(): void
    {
        $this->assertTrue($this->overlaps('2026-01-01', '2026-02-02', '2026-02-01', '2026-03-01'));
        $this->assertTrue($this->overlaps('2026-01-01', '2026-03-01', '2026-01-10', '2026-01-20'), 'containment');
        $this->assertTrue($this->overlaps('2026-01-01', '2026-02-01', '2026-01-01', '2026-02-01'), 'identical');
    }

    public function test_a_one_day_period_covers_exactly_its_first_day(): void
    {
        $this->assertFalse($this->overlaps('2026-01-01', '2026-01-02', '2026-01-02', '2026-01-03'));
        $this->assertTrue($this->overlaps('2026-01-01', '2026-01-02', '2026-01-01', '2026-01-02'));
    }

    public function test_a_null_upper_bound_is_an_open_ended_period(): void
    {
        $open = "daterange('2026-02-01', NULL, '[)')";

        $this->assertTrue((bool) $this->scalar("select upper_inf({$open})"));
        $this->assertTrue((bool) $this->scalar("select {$open} @> '2026-02-01'::date"));
        $this->assertTrue((bool) $this->scalar("select {$open} @> '9999-01-01'::date"), 'open-ended reaches arbitrarily far');
        $this->assertFalse((bool) $this->scalar("select {$open} @> '2026-01-31'::date"));
    }

    public function test_open_ended_periods_overlap_later_periods_but_not_earlier_or_adjacent_earlier_ones(): void
    {
        $this->assertTrue($this->overlaps('2026-02-01', null, '2030-01-01', '2030-02-01'));
        $this->assertTrue($this->overlaps('2026-02-01', null, '2027-01-01', null), 'two open-ended periods always overlap');
        $this->assertFalse($this->overlaps('2026-02-01', null, '2025-01-01', '2026-02-01'), 'adjacent to the start of an open period');
        $this->assertFalse($this->overlaps('2026-02-01', null, '2025-01-01', '2026-01-31'));
    }

    public function test_postgresql_canonicalises_inclusive_input_to_the_half_open_form(): void
    {
        $this->assertSame('[2026-01-01,2026-02-01)', $this->scalar("select daterange('2026-01-01', '2026-01-31', '[]')::text"));
        $this->assertSame('[2026-01-01,2026-02-01)', $this->scalar("select daterange('2026-01-01', '2026-02-01', '[)')::text"));
    }

    public function test_an_empty_range_overlaps_nothing_and_a_reversed_range_is_an_error(): void
    {
        $this->assertTrue((bool) $this->scalar("select isempty(daterange('2026-01-01', '2026-01-01', '[)'))"));
        $this->assertFalse($this->overlaps('2026-01-01', '2026-01-01', '2026-01-01', '2026-02-01'), 'the empty-range hazard');

        $error = $this->databaseError(fn () => $this->pg()->select("select daterange('2026-02-01', '2026-01-01', '[)')"));
        $this->assertSame('22000', Errors::sqlState($error));
    }

    // ---- GiST exclusion pattern ----------------------------------------------------------------------------

    private function createStream(string $table = 's02_temporal_probe', bool $withValidPeriodCheck = true): void
    {
        $this->pg()->statement(
            "create temporary table {$table} (".
            'id uuid primary key default gen_random_uuid(), '.
            'owner_id uuid not null, '.
            'effective_from date not null, '.
            'effective_to date null)',
        );

        $this->pg()->statement(Temporal::noOverlapConstraintSql($table, "{$table}_no_overlap", ['owner_id']));

        if ($withValidPeriodCheck) {
            $this->pg()->statement(Temporal::validPeriodCheckSql($table, "{$table}_valid_period"));
        }
    }

    private function insertPeriod(string $owner, ?string $from, ?string $to, string $table = 's02_temporal_probe'): void
    {
        // A nested transaction is a savepoint: a rejected row must not abort the outer test transaction.
        $this->pg()->transaction(fn () => $this->pg()->insert(
            "insert into {$table} (owner_id, effective_from, effective_to) values (?, ?, ?)",
            [$owner, $from, $to],
        ));
    }

    private function assertRejectedByExclusion(string $owner, ?string $from, ?string $to, string $message = ''): void
    {
        $error = $this->databaseError(fn () => $this->insertPeriod($owner, $from, $to));

        $this->assertTrue(Errors::isExclusionViolation($error), $message ?: 'expected 23P01, got '.(Errors::sqlState($error) ?? 'none'));
    }

    public function test_the_constraint_is_a_real_gist_exclusion_constraint(): void
    {
        $this->createStream();

        $this->assertSame('x', $this->scalar("select contype from pg_constraint where conname = 's02_temporal_probe_no_overlap'"));
        $this->assertSame('gist', $this->scalar(
            "select am.amname from pg_class i join pg_am am on am.oid = i.relam where i.relname = 's02_temporal_probe_no_overlap'",
        ));
    }

    public function test_adjacent_periods_for_the_same_owner_are_accepted(): void
    {
        $this->createStream();
        $owner = (string) Str::uuid7();

        $this->insertPeriod($owner, '2026-01-01', '2026-02-01');
        $this->insertPeriod($owner, '2026-02-01', '2026-03-01');
        $this->insertPeriod($owner, '2025-01-01', '2026-01-01');

        $this->assertSame(3, (int) $this->scalar('select count(*) from s02_temporal_probe'));
    }

    public function test_overlapping_periods_for_the_same_owner_are_rejected(): void
    {
        $this->createStream();
        $owner = (string) Str::uuid7();
        $this->insertPeriod($owner, '2026-01-01', '2026-02-01');

        $this->assertRejectedByExclusion($owner, '2026-01-15', '2026-02-15', 'partial overlap');
        $this->assertRejectedByExclusion($owner, '2025-12-15', '2026-01-02', 'overlap by one day at the start');
        $this->assertRejectedByExclusion($owner, '2026-01-10', '2026-01-20', 'contained');
        $this->assertRejectedByExclusion($owner, '2025-01-01', '2027-01-01', 'containing');
        $this->assertRejectedByExclusion($owner, '2026-01-01', '2026-02-01', 'identical');

        $this->assertSame(1, (int) $this->scalar('select count(*) from s02_temporal_probe'));
    }

    public function test_different_owners_may_have_overlapping_periods(): void
    {
        $this->createStream();

        $this->insertPeriod((string) Str::uuid7(), '2026-01-01', '2026-02-01');
        $this->insertPeriod((string) Str::uuid7(), '2026-01-01', '2026-02-01');

        $this->assertSame(2, (int) $this->scalar('select count(*) from s02_temporal_probe'));
    }

    public function test_an_open_ended_period_blocks_every_later_period_but_not_an_adjacent_earlier_one(): void
    {
        $this->createStream();
        $owner = (string) Str::uuid7();

        $this->insertPeriod($owner, '2026-02-01', null);

        $this->assertRejectedByExclusion($owner, '2030-01-01', '2030-02-01', 'later period inside the open range');
        $this->assertRejectedByExclusion($owner, '2027-01-01', null, 'second open-ended period');
        $this->assertRejectedByExclusion($owner, '2026-01-01', '2026-02-02', 'one day into the open range');

        $this->insertPeriod($owner, '2025-01-01', '2026-02-01'); // ends exactly where the open period starts
        $this->assertSame(2, (int) $this->scalar('select count(*) from s02_temporal_probe'));
    }

    public function test_closing_an_open_ended_period_then_starting_the_next_on_the_same_day_is_accepted(): void
    {
        $this->createStream();
        $owner = (string) Str::uuid7();
        $this->insertPeriod($owner, '2026-01-01', null);

        $this->pg()->update("update s02_temporal_probe set effective_to = '2026-06-01' where owner_id = ?", [$owner]);
        $this->insertPeriod($owner, '2026-06-01', null);

        $this->assertSame(2, (int) $this->scalar('select count(*) from s02_temporal_probe where owner_id = ?', [$owner]));
    }

    public function test_updating_a_period_so_that_it_overlaps_is_rejected(): void
    {
        $this->createStream();
        $owner = (string) Str::uuid7();
        $this->insertPeriod($owner, '2026-01-01', '2026-02-01');
        $this->insertPeriod($owner, '2026-02-01', '2026-03-01');

        $error = $this->databaseError(fn () => $this->pg()->transaction(fn () => $this->pg()->update(
            "update s02_temporal_probe set effective_to = '2026-02-15' where effective_from = '2026-01-01'",
        )));

        $this->assertTrue(Errors::isExclusionViolation($error));
    }

    public function test_empty_and_reversed_periods_are_rejected_by_the_valid_period_check(): void
    {
        $this->createStream();
        $owner = (string) Str::uuid7();

        foreach ([['2026-01-01', '2026-01-01'], ['2026-02-01', '2026-01-01']] as [$from, $to]) {
            $error = $this->databaseError(fn () => $this->insertPeriod($owner, $from, $to));

            $this->assertTrue(Errors::isCheckViolation($error), "{$from}..{$to} => ".(Errors::sqlState($error) ?? 'none'));
        }
    }

    public function test_the_exclusion_constraint_alone_does_not_stop_an_empty_period_which_is_why_the_check_is_mandatory(): void
    {
        $this->createStream('s02_temporal_unsafe', withValidPeriodCheck: false);
        $owner = (string) Str::uuid7();

        $this->insertPeriod($owner, '2026-01-01', '2026-02-01', 's02_temporal_unsafe');
        $this->insertPeriod($owner, '2026-01-10', '2026-01-10', 's02_temporal_unsafe'); // empty range slips through

        $this->assertSame(2, (int) $this->scalar('select count(*) from s02_temporal_unsafe'));
    }

    public function test_a_null_effective_from_is_rejected(): void
    {
        $this->createStream();

        $error = $this->databaseError(fn () => $this->insertPeriod((string) Str::uuid7(), null, '2026-02-01'));

        $this->assertTrue(Errors::isNotNullViolation($error));
    }

    public function test_the_drop_constraint_sql_removes_the_protection(): void
    {
        $this->createStream();
        $owner = (string) Str::uuid7();
        $this->insertPeriod($owner, '2026-01-01', '2026-02-01');

        $this->pg()->statement(Temporal::dropConstraintSql('s02_temporal_probe', 's02_temporal_probe_no_overlap'));
        $this->insertPeriod($owner, '2026-01-15', '2026-02-15');

        $this->assertSame(2, (int) $this->scalar('select count(*) from s02_temporal_probe'));
    }
}
