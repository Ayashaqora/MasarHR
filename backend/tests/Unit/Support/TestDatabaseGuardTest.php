<?php

namespace Tests\Unit\Support;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\TestDatabaseGuard;

class TestDatabaseGuardTest extends TestCase
{
    #[DataProvider('safeTargets')]
    public function test_accepts_only_the_isolated_postgresql_test_database(string $database): void
    {
        $this->assertNull(TestDatabaseGuard::violation('pgsql', $database));
    }

    /** @return array<string, array{string}> */
    public static function safeTargets(): array
    {
        return [
            'test database' => ['masarhr_test'],
            'parallel worker 1' => ['masarhr_test_1'],
            'parallel worker 12' => ['masarhr_test_12'],
        ];
    }

    #[DataProvider('unsafeTargets')]
    public function test_refuses_every_other_database(?string $database): void
    {
        $this->assertNotNull(TestDatabaseGuard::violation('pgsql', $database));
    }

    /** @return array<string, array{?string}> */
    public static function unsafeTargets(): array
    {
        return [
            'normal development database' => ['masarhr'],
            'maintenance database' => ['postgres'],
            'template database' => ['template1'],
            'unrelated database' => ['insightdraft'],
            'production-like name' => ['masarhr_production'],
            'test as a prefix only' => ['masarhr_testing'],
            'suffix after test' => ['masarhr_test_x'],
            'test as a suffix' => ['prod_masarhr_test'],
            'empty' => [''],
            'null' => [null],
        ];
    }

    #[DataProvider('otherDrivers')]
    public function test_refuses_sqlite_and_every_other_driver_even_with_a_valid_name(?string $driver): void
    {
        $this->assertStringContainsString('pgsql', (string) TestDatabaseGuard::violation($driver, 'masarhr_test'));
    }

    /** @return array<string, array{?string}> */
    public static function otherDrivers(): array
    {
        return [
            'sqlite' => ['sqlite'],
            'mysql' => ['mysql'],
            'mariadb' => ['mariadb'],
            'sqlsrv' => ['sqlsrv'],
            'null' => [null],
        ];
    }
}
