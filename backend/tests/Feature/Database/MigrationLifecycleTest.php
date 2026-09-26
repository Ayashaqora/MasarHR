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

    private const MARITAL_STATUS_ALIASES_TABLE_MIGRATION = 'database/migrations/2026_09_26_000019_create_ref_marital_status_aliases_table.php';

    private const MARITAL_STATUS_ALIASES_SEED_MIGRATION = 'database/migrations/2026_09_26_000020_seed_ref_marital_status_aliases.php';

    /** S06 migrations, in up() order (down() is applied in reverse — see rollbackS06()). */
    private const S06_MIGRATIONS = [
        'database/migrations/2026_09_26_000021_create_ref_monthly_cadre_categories_table.php',
        'database/migrations/2026_09_26_000022_create_ref_contract_based_population_categories_table.php',
        'database/migrations/2026_09_26_000023_create_ref_specialty_cadre_category_mappings_table.php',
        'database/migrations/2026_09_26_000024_create_ref_job_title_administrator_classifications_table.php',
        'database/migrations/2026_09_26_000025_create_ref_contract_type_population_mappings_table.php',
        'database/migrations/2026_09_26_000026_seed_ref_employment_status_details.php',
        'database/migrations/2026_09_26_000027_seed_ref_employment_status_detail_behaviors.php',
        'database/migrations/2026_09_26_000028_seed_ref_monthly_cadre_categories.php',
        'database/migrations/2026_09_26_000029_seed_ref_contract_based_population_categories.php',
    ];

    /**
     * S07 migrations, in up() order (down() is applied in reverse — see
     * test_s07_migrations_roll_back_and_reapply_cleanly()). Dated 2026_09_27 (a day after S05/S06)
     * deliberately, so this stage's own migrations never collide with dropReferenceSchemaObjects()'s
     * blanket '2026_09_26%' migrations-table cleanup below.
     */
    private const S07_MIGRATIONS = [
        'database/migrations/2026_09_27_000001_create_org_organizational_units_table.php',
        'database/migrations/2026_09_27_000002_seed_security_organization_permissions.php',
    ];

    /**
     * S08 migrations, in up() order. Dated 2026_09_28 (a day after S07) deliberately, so this
     * stage's own migrations never collide with dropOrganizationSchemaObjects()'s blanket
     * '2026_09_27%' migrations-table cleanup, nor with S07's own table.
     */
    private const S08_MIGRATIONS = [
        'database/migrations/2026_09_28_000001_create_security_organizational_scope_grants_table.php',
        'database/migrations/2026_09_28_000002_seed_security_organizational_scope_permission.php',
    ];

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
        // by the time S03/S04/S05 exist, `security`, `audit`, and `ref` legitimately are not (see
        // docs/security-access-foundation.md, docs/audit-command-infrastructure-specification.md,
        // and docs/reference-data-foundation-specification.md), so this test drains all three first
        // and restores them via migrateTestDatabase() in tearDown, the same way it always restores
        // S02's own probe objects.
        // Dropped before dropSecuritySchemaObjects(): organizational_scope_grants (S08) carries FKs
        // to security.principals, so it must go before the S03 tables it points to, mirroring the
        // existing most-dependent-first ordering within dropSecuritySchemaObjects() itself.
        $this->dropSecurityOrganizationalScopeSchemaObjects();
        $this->dropSecuritySchemaObjects();
        $this->dropAuditSchemaObjects();
        $this->dropReferenceSchemaObjects();
        $this->dropOrganizationSchemaObjects();

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
        $this->assertSame(7, (int) $this->scalar(
            "select count(*) from information_schema.tables where table_schema = 'security'"
        ), 'no S03/S08 data was lost — the original 6 S03 tables plus S08\'s organizational_scope_grants');
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

    private function dropReferenceSchemaObjects(): void
    {
        // Dependency order: the three S06 mapping tables reference specialties/job_titles/
        // contract_types and the two new S06 catalogs, so they drop first; the behavior table
        // references employment_status_details, which references employment_status_categories;
        // marital_status_aliases references marital_statuses (S05 CORRECTIVE-01 — added on top of
        // the original 16 S05 tables, so it must drop before the table it points to); every other
        // ref table is a standalone simple reference-value table with no inbound foreign key.
        foreach ([
            'specialty_cadre_category_mappings', 'job_title_administrator_classifications', 'contract_type_population_mappings',
            'monthly_cadre_categories', 'contract_based_population_categories',
            'employment_status_detail_behaviors', 'employment_status_details', 'employment_status_categories',
            'decision_types', 'marital_status_aliases', 'marital_statuses', 'genders',
            'leave_statuses', 'leave_types', 'supervisory_titles', 'specialties', 'job_titles',
            'academic_degrees', 'qualification_types', 'employment_categories', 'contract_types', 'employment_types',
        ] as $table) {
            $this->pg()->statement("drop table if exists ref.{$table} cascade");
        }
        DB::table('migrations')->where('migration', 'like', '2026_09_26%')->delete();
    }

    /**
     * S07's sole table plus its permission-seed migration (dated 2026_09_27 deliberately — see
     * S07_MIGRATIONS — so this cleanup never overlaps dropReferenceSchemaObjects()'s '2026_09_26%'
     * pattern above). security.permissions itself is already dropped wholesale by
     * dropSecuritySchemaObjects(), so the two organization.* rows it seeds need no separate delete.
     */
    private function dropOrganizationSchemaObjects(): void
    {
        $this->pg()->statement('drop table if exists org.organizational_units cascade');
        DB::table('migrations')->where('migration', 'like', '2026_09_27%')->delete();
    }

    /**
     * S08's sole table plus its permission-seed migration (dated 2026_09_28 deliberately — see
     * S08_MIGRATIONS — so this cleanup never overlaps dropOrganizationSchemaObjects()'s '2026_09_27%'
     * pattern above). security.permissions itself is already dropped wholesale by
     * dropSecuritySchemaObjects(), so the one security.organization_scopes.manage row it seeds needs
     * no separate delete.
     */
    private function dropSecurityOrganizationalScopeSchemaObjects(): void
    {
        $this->pg()->statement('drop table if exists security.organizational_scope_grants cascade');
        DB::table('migrations')->where('migration', 'like', '2026_09_28%')->delete();
    }

    /**
     * S05 CORRECTIVE-01 §12 ("migration rollback/reapply"): round-trips only the two corrective
     * migrations directly, the same batch-independent way rollbackS02() exercises S02 — calling
     * down()/up() directly rather than through `migrate:rollback`/`migrate`, which is what lets
     * this run safely without disturbing any other migration's recorded batch.
     */
    public function test_marital_status_aliases_corrective_migrations_roll_back_and_reapply_cleanly(): void
    {
        $countBefore = (int) $this->scalar('select count(*) from ref.marital_status_aliases');
        $this->assertGreaterThan(0, $countBefore, 'fixture assumption: the corrective seed migration already ran');

        DB::transaction(function (): void {
            $this->migration(self::MARITAL_STATUS_ALIASES_SEED_MIGRATION)->down();
            DB::table('migrations')->where('migration', 'like', '%seed_ref_marital_status_aliases')->delete();
        });
        $this->assertSame(0, (int) $this->scalar('select count(*) from ref.marital_status_aliases'));

        DB::transaction(function (): void {
            $this->migration(self::MARITAL_STATUS_ALIASES_TABLE_MIGRATION)->down();
            DB::table('migrations')->where('migration', 'like', '%create_ref_marital_status_aliases_table')->delete();
        });
        $this->assertSame(0, (int) $this->scalar(
            "select count(*) from information_schema.tables where table_schema = 'ref' and table_name = 'marital_status_aliases'"
        ), 'down() must drop the table itself, not just its rows');

        // Reapply both — migrateTestDatabase() runs exactly the migrations missing from the
        // `migrations` table, in filename order, so the table is created before it is seeded.
        $this->migrateTestDatabase();

        $this->assertSame($countBefore, (int) $this->scalar('select count(*) from ref.marital_status_aliases'));
    }

    /**
     * S06 spec §24 ("migration up/down, ... rollback atomicity ... migration rollback preserving
     * S01–S05"): round-trips all 9 S06 migrations directly, the same batch-independent way
     * test_marital_status_aliases_corrective_migrations_roll_back_and_reapply_cleanly() already
     * exercises the two CORRECTIVE-01 migrations — down() in reverse dependency order (seeds
     * before schema, mapping tables before the catalogs/details they reference), each wrapped in
     * its own DB::transaction() for atomicity, then migrateTestDatabase() reapplies everything
     * missing from the `migrations` table in filename order.
     */
    public function test_s06_migrations_roll_back_and_reapply_cleanly(): void
    {
        $cadreCategoriesBefore = (int) $this->scalar('select count(*) from ref.monthly_cadre_categories');
        $populationCategoriesBefore = (int) $this->scalar('select count(*) from ref.contract_based_population_categories');
        $detailsBefore = (int) $this->scalar('select count(*) from ref.employment_status_details');
        $behaviorsBefore = (int) $this->scalar('select count(*) from ref.employment_status_detail_behaviors');

        $this->assertSame(13, $detailsBefore, 'fixture assumption: the S06 employment_status_details seed already ran');
        $this->assertSame(12, $cadreCategoriesBefore, 'fixture assumption: the S06 monthly_cadre_categories seed already ran');
        $this->assertSame(2, $populationCategoriesBefore, 'fixture assumption: the S06 contract_based_population_categories seed already ran');

        foreach (array_reverse(self::S06_MIGRATIONS) as $path) {
            DB::transaction(function () use ($path): void {
                $this->migration($path)->down();
                $migrationName = pathinfo($path, PATHINFO_FILENAME);
                DB::table('migrations')->where('migration', $migrationName)->delete();
            });
        }

        $this->assertSame(0, (int) $this->scalar(
            "select count(*) from information_schema.tables where table_schema = 'ref' and table_name in ('monthly_cadre_categories', 'contract_based_population_categories', 'specialty_cadre_category_mappings', 'job_title_administrator_classifications', 'contract_type_population_mappings')"
        ), 'down() must drop every S06 table, not just its rows');
        $this->assertSame(0, (int) $this->scalar('select count(*) from ref.employment_status_details'), 'the S06 detail seed rows must be gone');
        $this->assertSame(0, (int) $this->scalar('select count(*) from ref.employment_status_detail_behaviors'), 'the S06 behavior seed rows must be gone');

        // S01–S05 objects the S06 migrations never touched must survive untouched.
        $this->assertSame(4, (int) $this->scalar('select count(*) from ref.marital_statuses'));
        $this->assertGreaterThan(0, (int) $this->scalar('select count(*) from ref.marital_status_aliases'));

        $this->migrateTestDatabase();

        $this->assertSame($detailsBefore, (int) $this->scalar('select count(*) from ref.employment_status_details'));
        $this->assertSame($behaviorsBefore, (int) $this->scalar('select count(*) from ref.employment_status_detail_behaviors'));
        $this->assertSame($cadreCategoriesBefore, (int) $this->scalar('select count(*) from ref.monthly_cadre_categories'));
        $this->assertSame($populationCategoriesBefore, (int) $this->scalar('select count(*) from ref.contract_based_population_categories'));
    }

    /**
     * S07 spec §21/§23 ("migration up/down ... rollback atomicity"): round-trips both S07
     * migrations directly, the same batch-independent way test_s06_migrations_roll_back_and_reapply_
     * cleanly() exercises S06's — down() in reverse order (the permission seed before the table it
     * depends on for its FK-free insert), each wrapped in its own DB::transaction(), then
     * migrateTestDatabase() reapplies everything missing from the `migrations` table in filename
     * order (the table-creation migration, dated 2026_09_27_000001, sorts before the seed,
     * 2026_09_27_000002, so the table exists before it is seeded).
     */
    public function test_s07_migrations_roll_back_and_reapply_cleanly(): void
    {
        $organizationPermissionsBefore = (int) $this->scalar("select count(*) from security.permissions where module = 'organization'");
        $this->assertSame(2, $organizationPermissionsBefore, 'fixture assumption: the S07 organization permission seed already ran');

        // S08's security.organizational_scope_grants carries a RESTRICT FK to
        // org.organizational_units (spec §10), so it must be rolled back before S07's
        // table-creation migration can drop that table. Rolled back via each S08 migration's own
        // down() (not the raw-SQL dropSecurityOrganizationalScopeSchemaObjects() helper used
        // elsewhere for a "drop the whole schema" scenario) so the seed migration's own down()
        // deletes exactly its one permission row — the same reason this loop below rolls back S07
        // via down() rather than a raw drop. migrateTestDatabase() at the end reapplies both S08
        // migrations along with S07's.
        foreach (array_reverse(self::S08_MIGRATIONS) as $path) {
            DB::transaction(function () use ($path): void {
                $this->migration($path)->down();
                $migrationName = pathinfo($path, PATHINFO_FILENAME);
                DB::table('migrations')->where('migration', $migrationName)->delete();
            });
        }

        foreach (array_reverse(self::S07_MIGRATIONS) as $path) {
            DB::transaction(function () use ($path): void {
                $this->migration($path)->down();
                $migrationName = pathinfo($path, PATHINFO_FILENAME);
                DB::table('migrations')->where('migration', $migrationName)->delete();
            });
        }

        $this->assertSame(0, (int) $this->scalar(
            "select count(*) from information_schema.tables where table_schema = 'org' and table_name = 'organizational_units'"
        ), 'down() must drop the org.organizational_units table itself, not just its rows');
        $this->assertSame(0, (int) $this->scalar("select count(*) from security.permissions where module = 'organization'"), 'the S07 permission seed rows must be gone');

        // S01–S06 objects the S07 migrations never touched must survive untouched.
        $this->assertGreaterThan(0, (int) $this->scalar('select count(*) from security.permissions'));
        $this->assertSame(4, (int) $this->scalar('select count(*) from ref.marital_statuses'));

        $this->migrateTestDatabase();

        $this->assertSame($organizationPermissionsBefore, (int) $this->scalar("select count(*) from security.permissions where module = 'organization'"));
        $this->assertSame(0, (int) $this->scalar('select count(*) from org.organizational_units'), 'S07 seeds zero organizational_units rows (spec §24)');
    }

    /**
     * S08 spec §21/§23-equivalent ("migration up/down ... rollback atomicity"): round-trips both
     * S08 migrations directly, the same batch-independent way test_s07_migrations_roll_back_and_
     * reapply_cleanly() exercises S07's — down() in reverse order (the permission seed before the
     * table it depends on for its FK-free insert), each wrapped in its own DB::transaction(), then
     * migrateTestDatabase() reapplies everything missing from the `migrations` table in filename
     * order (the table-creation migration, dated 2026_09_28_000001, sorts before the seed,
     * 2026_09_28_000002, so the table exists before it is seeded).
     */
    public function test_s08_migrations_roll_back_and_reapply_cleanly(): void
    {
        $scopePermissionsBefore = (int) $this->scalar("select count(*) from security.permissions where code = 'security.organization_scopes.manage'");
        $this->assertSame(1, $scopePermissionsBefore, 'fixture assumption: the S08 permission seed already ran');

        foreach (array_reverse(self::S08_MIGRATIONS) as $path) {
            DB::transaction(function () use ($path): void {
                $this->migration($path)->down();
                $migrationName = pathinfo($path, PATHINFO_FILENAME);
                DB::table('migrations')->where('migration', $migrationName)->delete();
            });
        }

        $this->assertSame(0, (int) $this->scalar(
            "select count(*) from information_schema.tables where table_schema = 'security' and table_name = 'organizational_scope_grants'"
        ), 'down() must drop the security.organizational_scope_grants table itself, not just its rows');
        $this->assertSame(0, (int) $this->scalar("select count(*) from security.permissions where code = 'security.organization_scopes.manage'"), 'the S08 permission seed row must be gone');

        // S01–S07 objects the S08 migrations never touched must survive untouched.
        $this->assertGreaterThan(0, (int) $this->scalar("select count(*) from security.permissions where module = 'organization'"));
        $this->assertSame(1, (int) $this->scalar(
            "select count(*) from information_schema.tables where table_schema = 'org' and table_name = 'organizational_units'"
        ), 'org.organizational_units itself (S07) must still exist, untouched by S08\'s rollback');

        $this->migrateTestDatabase();

        $this->assertSame($scopePermissionsBefore, (int) $this->scalar("select count(*) from security.permissions where code = 'security.organization_scopes.manage'"));
        $this->assertSame(0, (int) $this->scalar('select count(*) from security.organizational_scope_grants'), 'S08 seeds zero organizational_scope_grants rows');
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
