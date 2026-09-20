<?php

namespace Tests;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\TestDatabaseGuard;

/**
 * Base class for tests that need the real PostgreSQL test database.
 *
 * Before anything runs it verifies (against the live server) that the session is connected to the
 * isolated test database, then brings that database up to date with the MasarHR migrations. It never
 * uses SQLite and never resets the database wholesale.
 */
abstract class PostgresIntegrationTestCase extends TestCase
{
    private static bool $migrated = false;

    protected function setUp(): void
    {
        parent::setUp();

        TestDatabaseGuard::assertConnected();

        if (! self::$migrated) {
            $this->migrateTestDatabase();
            self::$migrated = true;
        }
    }

    protected function migrateTestDatabase(): void
    {
        TestDatabaseGuard::assertConnected();

        Artisan::call('migrate', ['--force' => true]);
    }

    protected function pg(): Connection
    {
        return DB::connection();
    }

    /** First column of the first row. */
    protected function scalar(string $sql, array $bindings = []): mixed
    {
        $row = $this->pg()->selectOne($sql, $bindings);

        return $row === null ? null : array_values((array) $row)[0];
    }

    /** Runs the closure and returns the database exception it raised, or fails if it raised none. */
    protected function databaseError(callable $work): \Throwable
    {
        try {
            $work();
        } catch (\Throwable $error) {
            return $error;
        }

        $this->fail('Expected the database to raise an error, but the statement succeeded.');
    }
}
