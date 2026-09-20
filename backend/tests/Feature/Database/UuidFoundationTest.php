<?php

namespace Tests\Feature\Database;

use App\Modules\Platform\Infrastructure\Persistence\Postgres\PostgresErrorClassifier;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Tests\PostgresIntegrationTestCase;

class UuidFoundationTest extends PostgresIntegrationTestCase
{
    private function versionOf(string $uuid): string
    {
        return $uuid[14];
    }

    public function test_database_default_gen_random_uuid_is_available_without_any_extension(): void
    {
        $uuid = (string) $this->scalar('select gen_random_uuid()::text');

        $this->assertTrue(Str::isUuid($uuid));
        $this->assertSame('4', $this->versionOf($uuid));
    }

    public function test_pg_uuid_type_is_used_and_default_generates_distinct_keys(): void
    {
        $this->pg()->statement('create temporary table s02_uuid_probe (id uuid primary key default gen_random_uuid(), label text)');

        try {
            $this->pg()->insert('insert into s02_uuid_probe (label) values (?), (?), (?)', ['a', 'b', 'c']);

            $this->assertSame('uuid', $this->scalar('select pg_typeof(id)::text from s02_uuid_probe limit 1'));
            $this->assertSame(3, (int) $this->scalar('select count(distinct id) from s02_uuid_probe'));
        } finally {
            $this->pg()->statement('drop table if exists pg_temp.s02_uuid_probe');
        }
    }

    public function test_application_generated_uuid_v7_round_trips_through_a_uuid_column(): void
    {
        $this->pg()->statement('create temporary table s02_uuid_probe (id uuid primary key)');

        try {
            $id = (string) Str::uuid7();
            $this->pg()->insert('insert into s02_uuid_probe (id) values (?)', [$id]);

            $this->assertSame($id, $this->scalar('select id::text from s02_uuid_probe'));
            $this->assertSame('7', $this->versionOf($id));
        } finally {
            $this->pg()->statement('drop table if exists pg_temp.s02_uuid_probe');
        }
    }

    public function test_uuid_v7_values_are_time_ordered(): void
    {
        $prefixes = [];
        foreach (range(1, 20) as $ignored) {
            $prefixes[] = substr(str_replace('-', '', (string) Str::uuid7()), 0, 12); // 48-bit millisecond timestamp
            usleep(2000);
        }

        $sorted = $prefixes;
        sort($sorted, SORT_STRING);

        $this->assertSame($sorted, $prefixes);
    }

    public function test_the_eloquent_uuid_trait_used_for_future_aggregates_generates_v7(): void
    {
        $model = new class extends Model
        {
            use HasUuids;
        };

        $id = $model->newUniqueId();

        $this->assertTrue(Str::isUuid($id));
        $this->assertSame('7', $this->versionOf($id));
        $this->assertTrue($model->getKeyType() === 'string' && ! $model->getIncrementing());
    }

    public function test_postgresql_rejects_a_malformed_uuid(): void
    {
        $this->pg()->statement('create temporary table s02_uuid_probe (id uuid primary key)');

        try {
            $error = $this->databaseError(fn () => $this->pg()->insert('insert into s02_uuid_probe (id) values (?)', ['12345']));

            $this->assertSame('22P02', PostgresErrorClassifier::sqlState($error));
        } finally {
            $this->pg()->statement('drop table if exists pg_temp.s02_uuid_probe');
        }
    }
}
