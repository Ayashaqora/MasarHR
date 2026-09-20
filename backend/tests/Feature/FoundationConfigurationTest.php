<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Verifies the S01 infrastructure baseline: PostgreSQL is the database
 * (never SQLite) and Redis is the architecture baseline for cache/queue.
 */
class FoundationConfigurationTest extends TestCase
{
    public function test_postgresql_is_the_configured_database_driver(): void
    {
        $connection = config('database.default');

        $this->assertSame('pgsql', $connection);
        $this->assertSame('pgsql', config("database.connections.{$connection}.driver"));
    }

    public function test_application_can_reach_postgresql(): void
    {
        $this->assertSame(1, (int) DB::selectOne('select 1 as ok')->ok);
        $this->assertSame('pgsql', DB::connection()->getDriverName());
    }

    public function test_redis_is_the_declared_cache_and_queue_baseline(): void
    {
        // Runtime env is overridden in phpunit.xml (array/sync) so tests do not
        // require a Redis server; the architecture baseline lives in these defaults.
        $this->assertSame('phpredis', config('database.redis.client'));
        $this->assertArrayHasKey('redis', config('cache.stores'));
        $this->assertArrayHasKey('redis', config('queue.connections'));
        $this->assertSame('redis', config('queue.connections.redis.driver'));
    }
}
