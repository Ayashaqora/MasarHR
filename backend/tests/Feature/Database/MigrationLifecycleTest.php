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

    /**
     * Rolls back exactly the two S02 migrations by invoking their down() directly, rather than
     * through `migrate:rollback --path=...`. Laravel's rollback command only ever considers the
     * *last recorded batch* (see Migrator::getMigrationsForRollback) and `--path` merely narrows
     * which of that batch's files are eligible — it does not reach into an earlier batch. Once a
     * later stage's migrations exist, S02's two migrations are no longer reliably "the last
     * batch" (that depended on nothing else having migrated since), so this drives the same
     * down()/up() calls the command would make, directly and batch-independently. This is not a
     * behavior change to the migrations themselves, only to how this isolated test exercises them.
     *
     * Laravel's Migrator normally wraps each migration's down() call in DB::transaction() when the
     * connection supports transactional DDL (PostgreSQL does), so a real `migrate:rollback` run
     * gets that atomicity for free. Calling ->down() directly bypasses the Migrator entirely, so
     * this helper must supply that same atomicity itself: without it, a failure partway through
     * (e.g. the schema-namespaces migration refusing to drop a still-populated `security` schema)
     * would leave the schemas already dropped earlier in this method gone for good instead of
     * rolled back with the rest.
     */
    private function rollbackS02(): void
    {
        TestDatabaseGuard::assertConnected();

        DB::transaction(function (): void {
            $this->migration(self::SCHEMAS_MIGRATION)->down();
            DB::table('migrations')->where('migration', 'like', '%create_database_schema_namespaces')->delete();

            $this->migration(self::EXTENSION_MIGRATION)->down();
            DB::table('migrations')->where('migration', 'like', '%enable_postgresql_btree_gist_extension')->delete();
        });
    }

    public function test_rollback_removes_the_namespaces_and_extension_and_migrating_again_restores_them(): void
    {
        // This round-trip is only meaningful while every schema the S02 migration owns is empty —
        // by the time S03/S04 exist, `security` and `audit` legitimately are not (see
        // docs/security-access-foundation.md and docs/audit-command-infrastructure-specification.md),
        // so this test drains both first and restores them via migrateTestDatabase() in tearDown,
        // the same way it always restores S02's own probe objects.
        $this->dropSecuritySchemaObjects();
        $this->dropAuditSchemaObjects();

        $this->assertSame(8, $this->schemaCount());
        $this->assertTrue($this->extensionInstalled());

        $this->rollbackS02();

        $this->assertSame(0, $this->schemaCount(), 'empty namespaces are removed by rollback');
        $this->assertFalse($this->extensionInstalled());

        $this->migrateTestDatabase();

        $this->assertSame(8, $this->schemaCount());
        $this->assertTrue($this->extensionInstalled());
    }

    public function test_rollback_refuses_to_destroy_a_non_empty_schema_and_leaves_everything_intact(): void
    {
        // `audit` already holds S04's audit_entries table, so it is itself sufficient to prove the
        // protection; the schemas are dropped in reverse of SCHEMAS order (migration, automation,
        // audit, security, reporting, org, ref, hr), and `audit` is reached before `security`.
        $migrationsBefore = (int) $this->scalar('select count(*) from migrations');

        $exception = $this->databaseError(fn () => $this->rollbackS02());

        $this->assertInstanceOf(RuntimeException::class, $exception);
        $this->assertStringContainsString('still contains objects', $exception->getMessage());
        $this->assertStringContainsString('"audit"', $exception->getMessage());

        // PostgreSQL DDL is transactional: the schemas dropped before the failure were restored too.
        $this->assertSame(8, $this->schemaCount());
        $this->assertSame(6, (int) $this->scalar(
            "select count(*) from information_schema.tables where table_schema = 'security'"
        ), 'no S03 data was lost');
        $this->assertSame(1, (int) $this->scalar(
            "select count(*) from information_schema.tables where table_schema = 'audit'"
        ), 'no S04 data was lost');
        $this->assertSame($migrationsBefore, (int) $this->scalar('select count(*) from migrations'), 'the migrations are still recorded as applied');
    }

    private function dropSecuritySchemaObjects(): void
    {
        foreach (['role_permissions', 'principal_roles', 'credentials', 'permissions', 'roles', 'principals'] as $table) {
            $this->pg()->statement("drop table if exists security.{$table} cascade");
        }
        DB::table('migrations')->where('migration', 'like', '2026_09_23%')->delete();
    }

    private function dropAuditSchemaObjects(): void
    {
        $this->pg()->statement('drop table if exists audit.audit_entries cascade');
        $this->pg()->statement('drop function if exists audit.reject_audit_mutation() cascade');
        DB::table('migrations')->where('migration', 'like', '2026_09_25%')->delete();
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
