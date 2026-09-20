<?php

namespace Tests\Support;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Hard safety net for every test that can touch a database.
 *
 * MasarHR tests may only ever run against PostgreSQL and only against the isolated test database
 * (masarhr_test, or masarhr_test_<n> for parallel workers). They must never be able to reach the
 * normal development database (masarhr), a maintenance database (postgres), an unknown
 * production-like database, or SQLite.
 */
final class TestDatabaseGuard
{
    /** The only database names tests may use. */
    public const ALLOWED_DATABASE_PATTERN = '/^masarhr_test(_[0-9]+)?$/';

    /** Pure decision function (unit-testable without any database). Returns a reason, or null when safe. */
    public static function violation(?string $driver, ?string $database): ?string
    {
        if ($driver !== 'pgsql') {
            return sprintf('tests must use the pgsql driver, but the driver is [%s].', $driver ?? 'null');
        }

        if ($database === null || preg_match(self::ALLOWED_DATABASE_PATTERN, $database) !== 1) {
            return sprintf(
                'tests may only use the isolated test database (masarhr_test), but the database is [%s].',
                $database ?? 'null',
            );
        }

        return null;
    }

    /** Configuration-level check: no connection is opened. Run for every test. */
    public static function assertConfigured(): void
    {
        $default = (string) config('database.default');

        self::fail(self::violation(
            config("database.connections.{$default}.driver"),
            config("database.connections.{$default}.database"),
        ), $default);

        if (! app()->environment('testing')) {
            throw new RuntimeException('Refusing to run tests: APP_ENV is not "testing".');
        }
    }

    /** Connection-level check: asks PostgreSQL which database this session is really connected to. */
    public static function assertConnected(): void
    {
        self::assertConfigured();

        $connection = DB::connection();
        $actual = (string) $connection->selectOne('select current_database() as name')->name;

        self::fail(self::violation($connection->getDriverName(), $actual), (string) config('database.default'));
    }

    private static function fail(?string $violation, string $connection): void
    {
        if ($violation !== null) {
            throw new RuntimeException("Refusing to run tests on connection [{$connection}]: {$violation}");
        }
    }
}
