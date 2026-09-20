<?php

namespace Tests\Feature\Database;

use App\Modules\Platform\Infrastructure\Persistence\Postgres\PostgresErrorClassifier as Errors;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\PostgresIntegrationTestCase;
use Tests\Support\TestDatabaseGuard;

/**
 * Exercises the S02 migrations' forward, rollback and re-run behaviour on the guarded test database.
 * Every test restores the migrated state in finally/tearDown so the rest of the suite is unaffected.
 */
class MigrationLifecycleTest extends PostgresIntegrationTestCase
{
    private const EXTENSION_MIGRATION = 'database/migrations/2026_09_20_000001_enable_postgresql_btree_gist_extension.php';

    private const SCHEMAS_MIGRATION = 'database/migrations/2026_09_20_000002_create_database_schema_namespaces.php';

    private const SCHEMAS = ['hr', 'ref', 'org', 'reporting', 'security', 'audit', 'automation', 'migration'];

    protected function tearDown(): void
    {
        // Whatever a test did, leave the test database fully migrated and free of probe objects.
        $this->pg()->statement('drop table if exists hr.s02_probe_nonempty');
        $this->pg()->statement('drop table if exists public.s02_probe_extension_dependency');
        $this->migrateTestDatabase();

        parent::tearDown();
    }

    private function schemaCount(): int
    {
        return (int) $this->scalar(
            'select count(*) from pg_namespace where nspname in ('.implode(',', array_fill(0, count(self::SCHEMAS), '?')).')',
            self::SCHEMAS,
        );
    }

    private function extensionInstalled(): bool
    {
        return (bool) $this->scalar("select exists (select 1 from pg_extension where extname = 'btree_gist')");
    }

    private function migration(string $relativePath): Migration
    {
        return require base_path($relativePath);
    }

    private function rollbackS02(): int
    {
        TestDatabaseGuard::assertConnected();

        return Artisan::call('migrate:rollback', [
            '--path' => [self::EXTENSION_MIGRATION, self::SCHEMAS_MIGRATION],
            '--force' => true,
        ]);
    }

    public function test_rollback_removes_the_namespaces_and_extension_and_migrating_again_restores_them(): void
    {
        $this->assertSame(8, $this->schemaCount());
        $this->assertTrue($this->extensionInstalled());

        $this->assertSame(0, $this->rollbackS02());

        $this->assertSame(0, $this->schemaCount(), 'empty namespaces are removed by rollback');
        $this->assertFalse($this->extensionInstalled());
        $this->assertSame(0, (int) $this->scalar('select count(*) from migrations'));

        $this->migrateTestDatabase();

        $this->assertSame(8, $this->schemaCount());
        $this->assertTrue($this->extensionInstalled());
    }

    public function test_rollback_refuses_to_destroy_a_non_empty_schema_and_leaves_everything_intact(): void
    {
        $this->pg()->statement('create table hr.s02_probe_nonempty (id uuid primary key)');
        $this->pg()->insert('insert into hr.s02_probe_nonempty (id) values (?)', ['0190f3a0-0000-7000-8000-000000000001']);

        $exception = $this->databaseError(fn () => $this->rollbackS02());

        $this->assertInstanceOf(RuntimeException::class, $exception);
        $this->assertStringContainsString('still contains objects', $exception->getMessage());
        $this->assertStringContainsString('"hr"', $exception->getMessage());

        // PostgreSQL DDL is transactional: the schemas dropped before the failure were restored too.
        $this->assertSame(8, $this->schemaCount());
        $this->assertSame(1, (int) $this->scalar('select count(*) from hr.s02_probe_nonempty'), 'no data was lost');
        $this->assertSame(2, (int) $this->scalar('select count(*) from migrations'), 'the migration is still recorded as applied');
    }

    public function test_extension_rollback_refuses_while_an_index_depends_on_it(): void
    {
        $this->pg()->statement('create table public.s02_probe_extension_dependency (owner_id uuid, p daterange)');
        $this->pg()->statement('alter table public.s02_probe_extension_dependency add exclude using gist (owner_id with =, p with &&)');

        $error = $this->databaseError(fn () => $this->migration(self::EXTENSION_MIGRATION)->down());

        $this->assertSame('2BP01', Errors::sqlState($error), 'dependent_objects_still_exist: RESTRICT, never CASCADE');
        $this->assertTrue($this->extensionInstalled());
        $this->assertSame(1, (int) $this->scalar("select count(*) from pg_constraint where conrelid = 'public.s02_probe_extension_dependency'::regclass and contype = 'x'"));
    }

    public function test_migrations_are_idempotent_when_run_again(): void
    {
        $this->migration(self::EXTENSION_MIGRATION)->up();
        $this->migration(self::SCHEMAS_MIGRATION)->up();

        $this->assertSame(8, $this->schemaCount());
        $this->assertTrue($this->extensionInstalled());
    }

    public function test_the_deferred_framework_migrations_are_not_part_of_the_active_migration_path(): void
    {
        Artisan::call('migrate:status');
        $status = Artisan::output();

        $this->assertStringContainsString('create_database_schema_namespaces', $status);
        $this->assertStringNotContainsString('create_users_table', $status);
        $this->assertStringNotContainsString('create_cache_table', $status);
        $this->assertStringNotContainsString('create_jobs_table', $status);
        $this->assertStringNotContainsString('Pending', $status, 'nothing is pending after migrating');

        // The files are preserved, just not scanned by Laravel.
        $this->assertFileExists(base_path('database/migrations/_deferred_framework/0001_01_01_000000_create_users_table.php'));
    }

    public function test_destructive_reset_commands_are_refusable_by_the_application(): void
    {
        DB::prohibitDestructiveCommands(true);

        try {
            $this->assertNotSame(0, Artisan::call('migrate:fresh'));
            $this->assertSame(8, $this->schemaCount(), 'the refused command changed nothing');
        } finally {
            DB::prohibitDestructiveCommands(false);
        }
    }
}
