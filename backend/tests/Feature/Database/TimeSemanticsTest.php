<?php

namespace Tests\Feature\Database;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Tests\PostgresIntegrationTestCase;

class TimeSemanticsTest extends PostgresIntegrationTestCase
{
    private const ZONES = ['UTC', 'Asia/Hebron', 'America/Los_Angeles', 'Pacific/Kiritimati'];

    protected function tearDown(): void
    {
        while ($this->pg()->transactionLevel() > 0) {
            $this->pg()->rollBack();
        }

        parent::tearDown();
    }

    public function test_a_business_date_is_stored_and_read_back_unchanged_in_every_session_time_zone(): void
    {
        $this->pg()->beginTransaction();
        $this->pg()->statement('create temporary table s02_date_probe (effective_from date not null) on commit drop');
        $this->pg()->insert('insert into s02_date_probe (effective_from) values (?)', ['2026-03-01']);

        foreach (self::ZONES as $zone) {
            $this->pg()->statement("set local time zone '{$zone}'");

            $this->assertSame('2026-03-01', $this->scalar('select effective_from::text from s02_date_probe'), $zone);
        }
    }

    public function test_deriving_a_date_from_a_timestamptz_depends_on_the_server_zone_which_is_why_business_dates_are_date_columns(): void
    {
        $this->pg()->beginTransaction();

        $instant = '2026-03-01 23:30:00+00';

        $this->pg()->statement("set local time zone 'UTC'");
        $this->assertSame('2026-03-01', $this->scalar('select (?::timestamptz)::date::text', [$instant]));

        $this->pg()->statement("set local time zone 'Pacific/Kiritimati'");
        $this->assertSame('2026-03-02', $this->scalar('select (?::timestamptz)::date::text', [$instant]), 'same instant, different calendar date');
    }

    public function test_timestamptz_preserves_the_instant_regardless_of_offset_written_or_session_zone(): void
    {
        $this->pg()->beginTransaction();
        $this->pg()->statement('create temporary table s02_instant_probe (recorded_at timestamptz not null) on commit drop');

        $written = Carbon::parse('2026-03-01T23:30:00+02:00'); // 21:30 UTC
        $this->pg()->insert('insert into s02_instant_probe (recorded_at) values (?)', [$written->toIso8601String()]);
        $this->pg()->insert('insert into s02_instant_probe (recorded_at) values (?)', ['2026-03-01T21:30:00+00:00']);

        foreach (self::ZONES as $zone) {
            $this->pg()->statement("set local time zone '{$zone}'");

            $epochs = collect($this->pg()->select('select extract(epoch from recorded_at)::bigint as e from s02_instant_probe'))->pluck('e')->all();

            $this->assertSame([$written->getTimestamp(), $written->getTimestamp()], array_map('intval', $epochs), $zone);
        }
    }

    public function test_laravel_blueprint_maps_timestamp_tz_to_timestamptz_and_plain_timestamp_to_the_naive_type(): void
    {
        $this->pg()->beginTransaction();

        Schema::create('s02_blueprint_probe', function ($table): void {
            $table->temporary();
            $table->timestampTz('recorded_at');
            $table->timestamp('naive_at')->nullable();
            $table->date('effective_from');
        });

        // format_type reports Laravel's default precision (timestamp(0)); the zone semantics are what matter.
        $this->assertSame('timestamp with time zone', $this->columnType('recorded_at'));
        // The trap the migration-discipline test forbids: timestamp() is WITHOUT time zone.
        $this->assertSame('timestamp without time zone', $this->columnType('naive_at'));
        $this->assertSame('date', $this->columnType('effective_from'));
    }

    private function columnType(string $column): string
    {
        $type = (string) $this->scalar(
            'select format_type(a.atttypid, a.atttypmod) from pg_attribute a '
            .'where a.attrelid = to_regclass(?) and a.attname = ? and not a.attisdropped',
            ['s02_blueprint_probe', $column],
        );

        // Drop the precision, e.g. "timestamp(0) with time zone" -> "timestamp with time zone".
        return (string) preg_replace('/\(\d+\)/', '', $type);
    }
}
