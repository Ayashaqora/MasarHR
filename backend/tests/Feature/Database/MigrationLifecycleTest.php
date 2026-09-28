<?php

namespace Tests\Feature\Database;

use App\Modules\Platform\Infrastructure\Persistence\Postgres\PostgresErrorClassifier as Errors;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
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

    /**
     * S09 migrations, in up() order. Dated 2026_09_29 (a day after S08) deliberately, so this
     * stage's own migrations never collide with dropOrganizationSchemaObjects()'s or
     * dropSecurityOrganizationalScopeSchemaObjects()'s blanket cleanup patterns above.
     */
    private const S09_MIGRATIONS = [
        'database/migrations/2026_09_29_000001_create_hr_persons_table.php',
        'database/migrations/2026_09_29_000002_create_hr_employment_relationships_table.php',
        'database/migrations/2026_09_29_000003_seed_ref_employment_types_permanent_and_contract.php',
        'database/migrations/2026_09_29_000004_seed_security_human_resources_permissions.php',
    ];

    /**
     * S10 migrations, in up() order. Dated 2026_09_30 (a day after S09) deliberately, so this
     * stage's own migrations never collide with dropHumanResourcesSchemaObjects()'s blanket
     * '2026_09_29%' migrations-table cleanup, nor with S09's own tables.
     */
    private const S10_MIGRATIONS = [
        'database/migrations/2026_09_30_000001_create_hr_employment_status_periods_table.php',
        'database/migrations/2026_09_30_000002_seed_security_employment_status_period_permissions.php',
    ];

    /**
     * S11 migrations, in up() order. Dated 2026_10_01 (a day after S10) deliberately, so this
     * stage's own migrations never collide with dropHumanResourcesSchemaObjects()'s blanket
     * '2026_09_29%'/'2026_09_30%' migrations-table cleanup patterns above, nor with S09/S10's own
     * tables.
     */
    private const S11_MIGRATIONS = [
        'database/migrations/2026_10_01_000001_create_hr_organizational_placement_periods_table.php',
        'database/migrations/2026_10_01_000002_seed_security_organizational_placement_period_permissions.php',
    ];

    /**
     * S12 migrations, in up() order. Dated 2026_10_02 (a day after S11) deliberately, so this
     * stage's own migrations never collide with dropHumanResourcesSchemaObjects()'s or
     * dropOrganizationalPlacementSchemaObjects()'s blanket cleanup patterns above, nor with
     * S09/S11's own tables.
     */
    private const S12_MIGRATIONS = [
        'database/migrations/2026_10_02_000001_create_hr_full_secondment_periods_table.php',
        'database/migrations/2026_10_02_000002_seed_security_full_secondment_period_permissions.php',
    ];

    /**
     * S14 migrations, in up() order. Dated 2026_10_04 (a day after S13's own 2026_10_03 seed)
     * deliberately, so this stage's own migrations never collide with dropReferenceSchemaObjects()'s
     * blanket '2026_09_26%' cleanup pattern, nor with the explicit 2026_10_03 deletion just below
     * it, nor with S09–S12's own tables. Unlike S07–S12, S14 creates no new table (§16) — both
     * migrations seed one row each into an already-existing table (ref.decision_types,
     * security.permissions), so there is no CREATE-then-SEED ordering concern between the two; they
     * are listed in migration-timestamp order purely for consistency with every other *_MIGRATIONS
     * constant here.
     */
    private const S14_MIGRATIONS = [
        'database/migrations/2026_10_04_000001_seed_ref_decision_types_transfer.php',
        'database/migrations/2026_10_04_000002_seed_security_transfer_permission.php',
    ];

    /**
     * S16 migrations, in up() order. Dated 2026_10_05 (a day after S14's own 2026_10_04) so this
     * stage's own migrations never collide with dropTransferSchemaObjects()'s blanket
     * '2026_10_04%' migrations-table cleanup, nor with S09/S07's own tables. Unlike S14, S16
     * creates its own new table (docs/workplace-assignment-foundation-specification.md §S16.5) —
     * carrying the identical two RESTRICT FKs (hr.employment_relationships, S09;
     * org.organizational_units, S07) already established by S11/S12 — so it participates in the
     * same "roll back before S09/S07's table-creation migrations can drop what it points to"
     * ordering those two stages' migrations already require.
     */
    private const S16_MIGRATIONS = [
        'database/migrations/2026_10_05_000001_create_hr_workplace_assignment_periods_table.php',
        'database/migrations/2026_10_05_000002_seed_ref_decision_types_assignment.php',
        'database/migrations/2026_10_05_000003_seed_security_workplace_assignment_period_permissions.php',
    ];

    /**
     * S20 migrations, in up() order. Dated 2026_10_06 (a day after S16's own 2026_10_05; S17–S19
     * added no migration) so this stage's own migrations never collide with
     * dropWorkplaceAssignmentSchemaObjects()'s blanket '2026_10_05%' cleanup. S20 creates one new
     * table (docs/employment-category-history-foundation-specification.md §S20.6) carrying two
     * RESTRICT FKs — hr.employment_relationships (S09) and ref.employment_categories (S05/S13) —
     * so it must roll back before S09's table-creation migration and before any drop of the ref
     * catalog it points to.
     */
    private const S20_MIGRATIONS = [
        'database/migrations/2026_10_06_000001_create_hr_employment_category_periods_table.php',
        'database/migrations/2026_10_06_000002_seed_security_employment_category_period_permissions.php',
    ];

    /**
     * S21 migrations, in up() order. Dated 2026_10_07 (a day after S20's own 2026_10_06) so this
     * stage's own migrations never collide with dropEmploymentCategoryPeriodSchemaObjects()'s
     * '2026_10_06%' cleanup. S21 creates one new table
     * (docs/employment-contract-foundation-specification.md §S21.6) with two RESTRICT FKs —
     * hr.employment_relationships (S09) and ref.contract_types (S05/S13) — so it must roll back
     * before S09's table-creation migration and before any drop of the ref catalog it points to.
     */
    private const S21_MIGRATIONS = [
        'database/migrations/2026_10_07_000001_create_hr_employment_contract_periods_table.php',
        'database/migrations/2026_10_07_000002_seed_security_employment_contract_period_permissions.php',
    ];

    /**
     * S22 migrations, in up() order. Dated 2026_10_08 so this cleanup never collides with S21's
     * '2026_10_07%'. One new table (docs/employment-job-title-history-foundation-specification.md
     * §S22.6) with RESTRICT FKs to hr.employment_relationships (S09) and ref.job_titles (S05/S13)
     * — so it rolls back before S09 and before any drop of the ref catalog it points to.
     */
    private const S22_MIGRATIONS = [
        'database/migrations/2026_10_08_000001_create_hr_employment_job_title_periods_table.php',
        'database/migrations/2026_10_08_000002_seed_security_employment_job_title_period_permissions.php',
    ];

    /**
     * S23 migrations, in up() order (2026_10_09). One new table
     * (docs/person-qualification-foundation-specification.md §S23.6) with RESTRICT FKs to
     * hr.persons (S09) and ref.academic_degrees / ref.qualification_types (S05/S13) — so it rolls
     * back before S09 and before any drop of the ref catalogs it points to.
     */
    private const S23_MIGRATIONS = [
        'database/migrations/2026_10_09_000001_create_hr_person_qualifications_table.php',
        'database/migrations/2026_10_09_000002_seed_security_person_qualification_permissions.php',
    ];

    /**
     * S24 migrations, in up() order (2026_10_10). Adds five nullable profile columns to hr.persons
     * (docs/person-profile-foundation-specification.md §S24.6) with RESTRICT FKs to ref.genders /
     * ref.marital_statuses (S05), plus one permission seed — so it rolls back before S09.
     */
    private const S24_MIGRATIONS = [
        'database/migrations/2026_10_10_000001_add_profile_columns_to_hr_persons_table.php',
        'database/migrations/2026_10_10_000002_seed_security_person_profile_permission.php',
    ];

    /**
     * S26 migrations, in up() order (2026_10_11). One new table
     * (docs/employee-specialty-history-foundation-specification.md §S26.6) with RESTRICT FKs to
     * hr.employment_relationships (S09) and ref.specialties (S05/S25) — so it rolls back before S09
     * and before any drop of the ref catalog it points to.
     */
    private const S26_MIGRATIONS = [
        'database/migrations/2026_10_11_000001_create_hr_employment_specialty_periods_table.php',
        'database/migrations/2026_10_11_000002_seed_security_employment_specialty_period_permissions.php',
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
        // Dropped first of all: hr.full_secondment_periods (S12),
        // hr.organizational_placement_periods (S11), and hr.workplace_assignment_periods (S16) all
        // carry RESTRICT FKs to both hr.employment_relationships and org.organizational_units, so
        // all three must go before dropHumanResourcesSchemaObjects() reaches
        // hr.employment_relationships below and before dropOrganizationSchemaObjects() reaches
        // org.organizational_units further down — most-dependent-first, same convention as every
        // other helper call in this method. Order between the three of them does not matter (none
        // references another).
        $this->dropFullSecondmentSchemaObjects();
        $this->dropOrganizationalPlacementSchemaObjects();
        $this->dropWorkplaceAssignmentSchemaObjects();
        // hr.employment_category_periods (S20) carries RESTRICT FKs to hr.employment_relationships
        // and ref.employment_categories, so it too must go before dropHumanResourcesSchemaObjects()
        // and dropReferenceSchemaObjects() below — otherwise their CASCADE drops would silently
        // strip its foreign keys while leaving the table (and its migration record) in place.
        $this->dropEmploymentCategoryPeriodSchemaObjects();
        // hr.employment_contract_periods (S21): same reasoning — RESTRICT FKs to
        // hr.employment_relationships and ref.contract_types.
        $this->dropEmploymentContractPeriodSchemaObjects();
        // hr.employment_job_title_periods (S22): same reasoning — RESTRICT FKs to
        // hr.employment_relationships and ref.job_titles.
        $this->dropEmploymentJobTitlePeriodSchemaObjects();
        // hr.person_qualifications (S23): RESTRICT FKs to hr.persons, ref.academic_degrees and
        // ref.qualification_types.
        $this->dropPersonQualificationSchemaObjects();
        // hr.persons profile columns (S24): RESTRICT FKs to ref.genders / ref.marital_statuses.
        $this->dropPersonProfileSchemaObjects();
        // hr.employment_specialty_periods (S26): RESTRICT FKs to hr.employment_relationships and
        // ref.specialties.
        $this->dropEmploymentSpecialtyPeriodSchemaObjects();
        // Dropped next: hr.employment_relationships (S09) carries RESTRICT FKs to both
        // hr.persons and ref.employment_types, and hr.employment_status_periods (S10) carries
        // RESTRICT FKs to both hr.employment_relationships and ref.employment_status_details, so
        // all three hr tables must go before dropReferenceSchemaObjects() below reaches
        // ref.employment_types/ref.employment_status_details — most-dependent-first, same
        // convention as every other helper call in this method.
        $this->dropHumanResourcesSchemaObjects();
        // Dropped before dropSecuritySchemaObjects(): organizational_scope_grants (S08) carries FKs
        // to security.principals, so it must go before the S03 tables it points to, mirroring the
        // existing most-dependent-first ordering within dropSecuritySchemaObjects() itself.
        $this->dropSecurityOrganizationalScopeSchemaObjects();
        $this->dropSecuritySchemaObjects();
        $this->dropAuditSchemaObjects();
        $this->dropReferenceSchemaObjects();
        $this->dropOrganizationSchemaObjects();
        // No schema object of its own to drop (S14 added no table, §16) — only un-records its two
        // seed migrations so migrateTestDatabase() below actually reapplies them.
        $this->dropTransferSchemaObjects();
        // hr.workplace_assignment_periods itself was already dropped by
        // dropWorkplaceAssignmentSchemaObjects() above (called before
        // dropHumanResourcesSchemaObjects()/dropOrganizationSchemaObjects() for the FK reasons
        // documented there); that call already un-records all three S16 migrations, so no further
        // action is needed here.

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

        // S13's seven-row ref.employment_categories seed
        // (docs/reference-catalog-administration-foundation-specification.md §8.1/§23) is dated
        // 2026_10_03, so the '2026_09_26%' pattern above does not reach its migration record —
        // exactly the same reason dropHumanResourcesSchemaObjects() below must separately delete
        // the 2026_09_29% employment_types seed's record. Without this, migrateTestDatabase()'s
        // plain `migrate --force` would find the seed migration still marked as run and skip it,
        // recreating an empty ref.employment_categories table instead of restoring its seven rows.
        DB::table('migrations')->where('migration', '2026_10_03_000001_seed_ref_employment_categories_grades')->delete();
    }

    /**
     * S14's two migrations (ADR-S14-001/ADR-S14-002, docs/transfer-foundation-specification.md
     * §16/§21) add no new table — a one-row ref.decision_types seed (TRANSFER/نقل) and a one-row
     * security.permissions seed — so, unlike dropOrganizationalPlacementSchemaObjects()/
     * dropFullSecondmentSchemaObjects(), this helper drops nothing structural. It exists purely to
     * un-record both migrations (dated 2026_10_04 deliberately, a day after S13's 2026_10_03 seed,
     * so this pattern never collides with dropReferenceSchemaObjects()'s blanket '2026_09_26%'
     * cleanup nor with the explicit 2026_10_03 deletion just above): without this,
     * migrateTestDatabase()'s plain `migrate --force` would find both seed migrations still marked
     * as run and skip them, leaving ref.decision_types and the S14 permission row permanently
     * empty/missing (silently breaking every later test that depends on either, e.g.
     * tests/Feature/HumanResources/TransferFoundationTest.php) for the rest of this process's test
     * run, not just the one test that calls this helper — exactly the failure mode the S13
     * addition immediately above this method already guards against for its own seed.
     */
    private function dropTransferSchemaObjects(): void
    {
        DB::table('migrations')->where('migration', 'like', '2026_10_04%')->delete();
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
     * S09's two tables plus its permission-seed migration (dated 2026_09_29 deliberately — see
     * S09_MIGRATIONS — so this cleanup never overlaps any earlier blanket cleanup pattern above),
     * and S10's one table plus its permission-seed migration (dated 2026_09_30 — see
     * S10_MIGRATIONS). security.permissions itself is already dropped wholesale by
     * dropSecuritySchemaObjects(), so the five S09 and two S10 hr.* permission rows it seeds need
     * no separate delete. The two ref.employment_types rows S09 seeds
     * (docs/person-employment-foundation-specification.md §7) are dropped along with the whole
     * ref.employment_types table by dropReferenceSchemaObjects() below, so they likewise need no
     * separate delete here — but hr.employment_relationships' RESTRICT FK to ref.employment_types
     * (and, transitively, hr.employment_status_periods' RESTRICT FK to
     * ref.employment_status_details) means this method must run before that one.
     * hr.employment_status_periods is dropped first: it is the only one of the three with a
     * RESTRICT FK pointing at another table this method also drops
     * (hr.employment_relationships).
     */
    private function dropHumanResourcesSchemaObjects(): void
    {
        $this->pg()->statement('drop table if exists hr.employment_status_periods cascade');
        $this->pg()->statement('drop table if exists hr.employment_relationships cascade');
        $this->pg()->statement('drop table if exists hr.persons cascade');
        DB::table('migrations')->where('migration', 'like', '2026_09_29%')->delete();
        DB::table('migrations')->where('migration', 'like', '2026_09_30%')->delete();
    }

    /**
     * S11's one table plus its permission-seed migration (dated 2026_10_01 deliberately — see
     * S11_MIGRATIONS — so this cleanup never overlaps dropHumanResourcesSchemaObjects()'s
     * '2026_09_29%'/'2026_09_30%' patterns above). security.permissions itself is already dropped
     * wholesale by dropSecuritySchemaObjects(), so the two hr.organizational_placement_periods.*
     * permission rows it seeds need no separate delete. hr.organizational_placement_periods
     * carries RESTRICT FKs to both hr.employment_relationships (S09) and org.organizational_units
     * (S07), so this method must run before dropHumanResourcesSchemaObjects() and before
     * dropOrganizationSchemaObjects() reach the tables they respectively drop.
     */
    private function dropOrganizationalPlacementSchemaObjects(): void
    {
        $this->pg()->statement('drop table if exists hr.organizational_placement_periods cascade');
        DB::table('migrations')->where('migration', 'like', '2026_10_01%')->delete();
    }

    /**
     * S12's one table plus its permission-seed migration (dated 2026_10_02 deliberately — see
     * S12_MIGRATIONS — so this cleanup never overlaps dropOrganizationalPlacementSchemaObjects()'s
     * '2026_10_01%' pattern above). security.permissions itself is already dropped wholesale by
     * dropSecuritySchemaObjects(), so the three hr.full_secondment_periods.* permission rows it
     * seeds need no separate delete. hr.full_secondment_periods carries RESTRICT FKs to both
     * hr.employment_relationships (S09) and org.organizational_units (S07) — the identical shape
     * as S11's hr.organizational_placement_periods — so this method must run before
     * dropHumanResourcesSchemaObjects() and before dropOrganizationSchemaObjects() reach the
     * tables they respectively drop. It carries no FK to hr.organizational_placement_periods
     * itself (S12 spec §7.2: "actual workplace" is a derived read-time query, never a persisted
     * reference), so this method's ordering relative to
     * dropOrganizationalPlacementSchemaObjects() does not matter.
     */
    private function dropFullSecondmentSchemaObjects(): void
    {
        $this->pg()->statement('drop table if exists hr.full_secondment_periods cascade');
        DB::table('migrations')->where('migration', 'like', '2026_10_02%')->delete();
    }

    /**
     * S16's one table plus its two seed migrations (dated 2026_10_05 deliberately — see
     * S16_MIGRATIONS — so this cleanup never overlaps dropTransferSchemaObjects()'s '2026_10_04%'
     * pattern above). security.permissions and ref.decision_types are each already dropped
     * wholesale by dropSecuritySchemaObjects()/dropReferenceSchemaObjects() respectively, so the
     * three hr.workplace_assignment_periods.* permission rows and the one ASSIGNMENT decision-type
     * row it seeds need no separate delete. hr.workplace_assignment_periods carries RESTRICT FKs
     * to both hr.employment_relationships (S09) and org.organizational_units (S07) — the identical
     * shape as S11's hr.organizational_placement_periods and S12's hr.full_secondment_periods — so
     * this method must run before dropHumanResourcesSchemaObjects() and before
     * dropOrganizationSchemaObjects() reach the tables they respectively drop. It carries no FK to
     * hr.organizational_placement_periods or hr.full_secondment_periods themselves (S16 spec
     * §S16.8: "actual workplace" is a derived read-time query, never a persisted reference, and the
     * two movement mechanisms are mutually exclusive at write time, not linked by any FK), so this
     * method's ordering relative to dropOrganizationalPlacementSchemaObjects()/
     * dropFullSecondmentSchemaObjects() does not matter.
     */
    private function dropWorkplaceAssignmentSchemaObjects(): void
    {
        $this->pg()->statement('drop table if exists hr.workplace_assignment_periods cascade');
        DB::table('migrations')->where('migration', 'like', '2026_10_05%')->delete();
    }

    /**
     * S20's one table plus its permission-seed migration (dated 2026_10_06 deliberately — see
     * S20_MIGRATIONS — so this cleanup never overlaps dropWorkplaceAssignmentSchemaObjects()'s
     * '2026_10_05%' pattern above). security.permissions itself is already dropped wholesale by
     * dropSecuritySchemaObjects(), so the two hr.employment_category_periods.* permission rows it
     * seeds need no separate delete. hr.employment_category_periods carries RESTRICT FKs to
     * hr.employment_relationships (S09) and ref.employment_categories (S05/S13), so this method
     * must run before dropHumanResourcesSchemaObjects() and dropReferenceSchemaObjects().
     */
    private function dropEmploymentCategoryPeriodSchemaObjects(): void
    {
        $this->pg()->statement('drop table if exists hr.employment_category_periods cascade');
        DB::table('migrations')->where('migration', 'like', '2026_10_06%')->delete();
    }

    /**
     * S21's one table plus its permission-seed migration (dated 2026_10_07 — see S21_MIGRATIONS).
     * security.permissions is dropped wholesale by dropSecuritySchemaObjects(), so its two
     * permission rows need no separate delete. Must run before dropHumanResourcesSchemaObjects()
     * and dropReferenceSchemaObjects() because of its RESTRICT FKs.
     */
    private function dropEmploymentContractPeriodSchemaObjects(): void
    {
        $this->pg()->statement('drop table if exists hr.employment_contract_periods cascade');
        DB::table('migrations')->where('migration', 'like', '2026_10_07%')->delete();
    }

    /** S22's one table plus its permission seed (2026_10_08 — see S22_MIGRATIONS). */
    private function dropEmploymentJobTitlePeriodSchemaObjects(): void
    {
        $this->pg()->statement('drop table if exists hr.employment_job_title_periods cascade');
        DB::table('migrations')->where('migration', 'like', '2026_10_08%')->delete();
    }

    /** S23's one table plus its permission seed (2026_10_09 — see S23_MIGRATIONS). */
    private function dropPersonQualificationSchemaObjects(): void
    {
        $this->pg()->statement('drop table if exists hr.person_qualifications cascade');
        DB::table('migrations')->where('migration', 'like', '2026_10_09%')->delete();
    }

    /** S26's one table plus its permission seed (2026_10_11 — see S26_MIGRATIONS). */
    private function dropEmploymentSpecialtyPeriodSchemaObjects(): void
    {
        $this->pg()->statement('drop table if exists hr.employment_specialty_periods cascade');
        DB::table('migrations')->where('migration', 'like', '2026_10_11%')->delete();
    }

    /** S24's five hr.persons profile columns plus its permission seed (2026_10_10 — see S24_MIGRATIONS). */
    private function dropPersonProfileSchemaObjects(): void
    {
        if ((int) $this->scalar("select count(*) from information_schema.tables where table_schema = 'hr' and table_name = 'persons'") === 1) {
            $this->pg()->statement('alter table hr.persons drop constraint if exists persons_gender_fk, drop constraint if exists persons_marital_status_fk');
        }
        DB::table('migrations')->where('migration', 'like', '2026_10_10%')->delete();
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

        // S12's hr.full_secondment_periods, S11's hr.organizational_placement_periods, and S16's
        // hr.workplace_assignment_periods all carry a RESTRICT FK to org.organizational_units (S12
        // spec §19, S11 spec §14, S16 spec §S16.5), so all three must be rolled back before S07's
        // table-creation migration can drop that table. Rolled back via each migration's own
        // down() — same reason as the S08 loop immediately below — so each seed migration's own
        // down() deletes exactly its own permission rows. migrateTestDatabase() at the end
        // reapplies the S16/S12/S11 migrations along with S07's and S08's.
        foreach (array_reverse(self::S16_MIGRATIONS) as $path) {
            DB::transaction(function () use ($path): void {
                $this->migration($path)->down();
                $migrationName = pathinfo($path, PATHINFO_FILENAME);
                DB::table('migrations')->where('migration', $migrationName)->delete();
            });
        }

        foreach (array_reverse(self::S12_MIGRATIONS) as $path) {
            DB::transaction(function () use ($path): void {
                $this->migration($path)->down();
                $migrationName = pathinfo($path, PATHINFO_FILENAME);
                DB::table('migrations')->where('migration', $migrationName)->delete();
            });
        }

        foreach (array_reverse(self::S11_MIGRATIONS) as $path) {
            DB::transaction(function () use ($path): void {
                $this->migration($path)->down();
                $migrationName = pathinfo($path, PATHINFO_FILENAME);
                DB::table('migrations')->where('migration', $migrationName)->delete();
            });
        }

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

    /**
     * S09 spec §21/§25 ("migration rollback/reapply"): round-trips all four S09 migrations
     * directly, the same batch-independent way test_s08_migrations_roll_back_and_reapply_
     * cleanly() exercises S08's — down() in reverse order (permission seed, then the
     * ref.employment_types seed, then employment_relationships, then persons — each a dependency
     * of the one before it), each wrapped in its own DB::transaction(), then migrateTestDatabase()
     * reapplies everything missing from the `migrations` table in filename order.
     *
     * S10's hr.employment_status_periods, S11's hr.organizational_placement_periods, S12's
     * hr.full_secondment_periods, and S16's hr.workplace_assignment_periods all carry a RESTRICT
     * FK to hr.employment_relationships (S10 spec §8, S11 spec §14, S12 spec §19, S16 spec
     * §S16.5), so — mirroring exactly how test_s07_migrations_roll_back_and_reapply_cleanly()
     * rolls back S08 (and now S16/S12/S11) before S07 for the identical reason — S16's own
     * migrations are rolled back first here, then S12's, then S11's, then S10's, via their own
     * down(), before S09's table-creation migration can drop hr.employment_relationships.
     */
    public function test_s09_migrations_roll_back_and_reapply_cleanly(): void
    {
        // module = 'human_resources' now covers S09's five permission rows, S10's two, S11's two,
        // S12's three, S14's one, S16's three, and S20's two (S10/S11/S12/S14/S16/S20 spec
        // §13/§16/§17/§13/§S16.14/§S20.12: none adds a new module name, since all extend the same
        // HumanResources module S09 owns), plus S21's, S22's and S23's two each, so the fixture
        // assumption below is 24, plus S24's one and S26's two, so 27.
        $hrPermissionsBefore = (int) $this->scalar("select count(*) from security.permissions where module = 'human_resources'");
        $employmentTypesBefore = (int) $this->scalar("select count(*) from ref.employment_types where code in ('permanent', 'contract')");

        $this->assertSame(27, $hrPermissionsBefore, 'fixture assumption: the S09, S10, S11, S12, S14, S16, S20, S21, S22, S23, S24, and S26 permission seeds already ran');
        $this->assertSame(2, $employmentTypesBefore, 'fixture assumption: the S09 employment-type seed already ran');

        // S26 first (RESTRICT FK to hr.employment_relationships), then S24 (columns on hr.persons),
        // then S23 (RESTRICT FK to hr.persons), then S22, S21 and S20 (each a RESTRICT FK to
        // hr.employment_relationships).
        foreach (array_reverse(self::S26_MIGRATIONS) as $path) {
            DB::transaction(function () use ($path): void {
                $this->migration($path)->down();
                $migrationName = pathinfo($path, PATHINFO_FILENAME);
                DB::table('migrations')->where('migration', $migrationName)->delete();
            });
        }

        foreach (array_reverse(self::S24_MIGRATIONS) as $path) {
            DB::transaction(function () use ($path): void {
                $this->migration($path)->down();
                $migrationName = pathinfo($path, PATHINFO_FILENAME);
                DB::table('migrations')->where('migration', $migrationName)->delete();
            });
        }

        foreach (array_reverse(self::S23_MIGRATIONS) as $path) {
            DB::transaction(function () use ($path): void {
                $this->migration($path)->down();
                $migrationName = pathinfo($path, PATHINFO_FILENAME);
                DB::table('migrations')->where('migration', $migrationName)->delete();
            });
        }

        foreach (array_reverse(self::S22_MIGRATIONS) as $path) {
            DB::transaction(function () use ($path): void {
                $this->migration($path)->down();
                $migrationName = pathinfo($path, PATHINFO_FILENAME);
                DB::table('migrations')->where('migration', $migrationName)->delete();
            });
        }

        foreach (array_reverse(self::S21_MIGRATIONS) as $path) {
            DB::transaction(function () use ($path): void {
                $this->migration($path)->down();
                $migrationName = pathinfo($path, PATHINFO_FILENAME);
                DB::table('migrations')->where('migration', $migrationName)->delete();
            });
        }

        foreach (array_reverse(self::S20_MIGRATIONS) as $path) {
            DB::transaction(function () use ($path): void {
                $this->migration($path)->down();
                $migrationName = pathinfo($path, PATHINFO_FILENAME);
                DB::table('migrations')->where('migration', $migrationName)->delete();
            });
        }

        foreach (array_reverse(self::S16_MIGRATIONS) as $path) {
            DB::transaction(function () use ($path): void {
                $this->migration($path)->down();
                $migrationName = pathinfo($path, PATHINFO_FILENAME);
                DB::table('migrations')->where('migration', $migrationName)->delete();
            });
        }

        foreach (array_reverse(self::S12_MIGRATIONS) as $path) {
            DB::transaction(function () use ($path): void {
                $this->migration($path)->down();
                $migrationName = pathinfo($path, PATHINFO_FILENAME);
                DB::table('migrations')->where('migration', $migrationName)->delete();
            });
        }

        foreach (array_reverse(self::S11_MIGRATIONS) as $path) {
            DB::transaction(function () use ($path): void {
                $this->migration($path)->down();
                $migrationName = pathinfo($path, PATHINFO_FILENAME);
                DB::table('migrations')->where('migration', $migrationName)->delete();
            });
        }

        foreach (array_reverse(self::S10_MIGRATIONS) as $path) {
            DB::transaction(function () use ($path): void {
                $this->migration($path)->down();
                $migrationName = pathinfo($path, PATHINFO_FILENAME);
                DB::table('migrations')->where('migration', $migrationName)->delete();
            });
        }

        foreach (array_reverse(self::S09_MIGRATIONS) as $path) {
            DB::transaction(function () use ($path): void {
                $this->migration($path)->down();
                $migrationName = pathinfo($path, PATHINFO_FILENAME);
                DB::table('migrations')->where('migration', $migrationName)->delete();
            });
        }

        $this->assertSame(0, (int) $this->scalar(
            "select count(*) from information_schema.tables where table_schema = 'hr' and table_name in ('persons', 'employment_relationships', 'employment_status_periods', 'organizational_placement_periods', 'full_secondment_periods', 'workplace_assignment_periods', 'employment_category_periods', 'employment_contract_periods', 'employment_job_title_periods', 'person_qualifications', 'employment_specialty_periods')"
        ), 'down() must drop all eleven tables themselves (S09\'s two plus S10\'s, S11\'s, S12\'s, S16\'s, S20\'s, S21\'s, S22\'s, S23\'s, and S26\'s, rolled back first), not just their rows');
        // 1, not 0: this loop rolls back S09/S10/S11/S12/S16. S14 (docs/transfer-foundation-
        // specification.md) adds no table with an FK forcing it to roll back before S09's own
        // table-creation migration (§16 — S14 has no table of its own at all), so its one
        // human_resources permission row is deliberately left behind here, exactly mirroring how
        // S07's organization-module permission is left behind (asserted just below).
        $this->assertSame(1, (int) $this->scalar("select count(*) from security.permissions where module = 'human_resources'"), 'the S09, S10, S11, S12, S16, S20, S21, S22, S23, S24, and S26 permission seed rows must be gone, leaving only S14\'s');
        $this->assertSame(0, (int) $this->scalar("select count(*) from ref.employment_types where code in ('permanent', 'contract')"), 'the S09 employment-type seed rows must be gone');
        $this->assertSame(0, (int) $this->scalar("select count(*) from ref.decision_types where code = 'ASSIGNMENT'"), 'the S16 decision-type seed row must be gone');

        // S01–S08 objects the S09/S10/S11/S12/S16 migrations never touched must survive untouched.
        $this->assertGreaterThan(0, (int) $this->scalar("select count(*) from security.permissions where module = 'organization'"));
        $this->assertSame(1, (int) $this->scalar(
            "select count(*) from information_schema.tables where table_schema = 'security' and table_name = 'organizational_scope_grants'"
        ), "security.organizational_scope_grants (S08) must still exist, untouched by S09/S10/S11/S12/S16's rollback");
        $this->assertSame(1, (int) $this->scalar(
            "select count(*) from information_schema.tables where table_schema = 'org' and table_name = 'organizational_units'"
        ), "org.organizational_units (S07) must still exist, untouched by S09/S10/S11/S12/S16's rollback");

        $this->migrateTestDatabase();

        $this->assertSame($hrPermissionsBefore, (int) $this->scalar("select count(*) from security.permissions where module = 'human_resources'"));
        $this->assertSame($employmentTypesBefore, (int) $this->scalar("select count(*) from ref.employment_types where code in ('permanent', 'contract')"));
        $this->assertSame(0, (int) $this->scalar('select count(*) from hr.persons'), 'S09 seeds zero persons rows');
        $this->assertSame(0, (int) $this->scalar('select count(*) from hr.employment_relationships'), 'S09 seeds zero employment_relationships rows');
        $this->assertSame(0, (int) $this->scalar('select count(*) from hr.employment_status_periods'), 'S10 seeds zero employment_status_periods rows');
        $this->assertSame(0, (int) $this->scalar('select count(*) from hr.organizational_placement_periods'), 'S11 seeds zero organizational_placement_periods rows');
        $this->assertSame(0, (int) $this->scalar('select count(*) from hr.full_secondment_periods'), 'S12 seeds zero full_secondment_periods rows');
        $this->assertSame(0, (int) $this->scalar('select count(*) from hr.workplace_assignment_periods'), 'S16 seeds zero workplace_assignment_periods rows');
        $this->assertSame(0, (int) $this->scalar('select count(*) from hr.employment_category_periods'), 'S20 seeds zero employment_category_periods rows');
        $this->assertSame(0, (int) $this->scalar('select count(*) from hr.employment_contract_periods'), 'S21 seeds zero employment_contract_periods rows');
        $this->assertSame(0, (int) $this->scalar('select count(*) from hr.employment_job_title_periods'), 'S22 seeds zero employment_job_title_periods rows');
        $this->assertSame(0, (int) $this->scalar('select count(*) from hr.person_qualifications'), 'S23 seeds zero person_qualifications rows');
        $this->assertSame(0, (int) $this->scalar('select count(*) from hr.employment_specialty_periods'), 'S26 seeds zero employment_specialty_periods rows');
        $this->assertSame(1, (int) $this->scalar("select count(*) from ref.decision_types where code = 'ASSIGNMENT'"));
    }

    /**
     * S10 spec §20 ("migration rollback/reapply"): round-trips both S10 migrations directly, the
     * same batch-independent way test_s09_migrations_roll_back_and_reapply_cleanly() exercises
     * S09's — down() in reverse order (permission seed, then the table itself), each wrapped in
     * its own DB::transaction(), then migrateTestDatabase() reapplies everything missing from the
     * `migrations` table in filename order.
     */
    public function test_s10_migrations_roll_back_and_reapply_cleanly(): void
    {
        $statusPeriodPermissionsBefore = (int) $this->scalar(
            "select count(*) from security.permissions where code in ('hr.employment_status_periods.view', 'hr.employment_status_periods.record')"
        );
        $this->assertSame(2, $statusPeriodPermissionsBefore, 'fixture assumption: the S10 permission seed already ran');

        foreach (array_reverse(self::S10_MIGRATIONS) as $path) {
            DB::transaction(function () use ($path): void {
                $this->migration($path)->down();
                $migrationName = pathinfo($path, PATHINFO_FILENAME);
                DB::table('migrations')->where('migration', $migrationName)->delete();
            });
        }

        $this->assertSame(0, (int) $this->scalar(
            "select count(*) from information_schema.tables where table_schema = 'hr' and table_name = 'employment_status_periods'"
        ), 'down() must drop the S10 table itself, not just its rows');
        $this->assertSame(0, (int) $this->scalar(
            "select count(*) from security.permissions where code in ('hr.employment_status_periods.view', 'hr.employment_status_periods.record')"
        ), 'the S10 permission seed rows must be gone');

        // S01–S09, S11, S12, S14, and S16 objects the S10 migrations never touched must survive
        // untouched. The count below is S09's five permissions plus S11's two plus S12's three plus
        // S14's one plus S16's three (14, not 5, 7, 10, or 11) — S10's rollback here never touches
        // hr.organizational_placement_periods, hr.full_secondment_periods, or
        // hr.workplace_assignment_periods, since all three tables' RESTRICT FKs point at
        // hr.employment_relationships and org.organizational_units, neither of which is
        // hr.employment_status_periods; S14 (docs/transfer-foundation-specification.md) adds no
        // table of its own at all (§16), only the one permission row counted here.
        $this->assertSame(1, (int) $this->scalar(
            "select count(*) from information_schema.tables where table_schema = 'hr' and table_name = 'employment_relationships'"
        ), "hr.employment_relationships (S09) must still exist, untouched by S10's rollback");
        $this->assertSame(1, (int) $this->scalar(
            "select count(*) from information_schema.tables where table_schema = 'hr' and table_name = 'organizational_placement_periods'"
        ), "hr.organizational_placement_periods (S11) must still exist, untouched by S10's rollback");
        $this->assertSame(1, (int) $this->scalar(
            "select count(*) from information_schema.tables where table_schema = 'hr' and table_name = 'full_secondment_periods'"
        ), "hr.full_secondment_periods (S12) must still exist, untouched by S10's rollback");
        $this->assertSame(1, (int) $this->scalar(
            "select count(*) from information_schema.tables where table_schema = 'hr' and table_name = 'workplace_assignment_periods'"
        ), "hr.workplace_assignment_periods (S16) must still exist, untouched by S10's rollback");
        // 22: the 14 above plus S20's, S21's, S22's and S23's two permissions each.
        $this->assertSame(25, (int) $this->scalar("select count(*) from security.permissions where module = 'human_resources' and code not like '%status_periods%'"));

        $this->migrateTestDatabase();

        $this->assertSame($statusPeriodPermissionsBefore, (int) $this->scalar(
            "select count(*) from security.permissions where code in ('hr.employment_status_periods.view', 'hr.employment_status_periods.record')"
        ));
        $this->assertSame(0, (int) $this->scalar('select count(*) from hr.employment_status_periods'), 'S10 seeds zero employment_status_periods rows');
    }

    /**
     * S11 spec §14/§19.1 ("migration rollback/reapply"): round-trips both S11 migrations
     * directly, the same batch-independent way test_s10_migrations_roll_back_and_reapply_cleanly()
     * exercises S10's — down() in reverse order (permission seed, then the table itself), each
     * wrapped in its own DB::transaction(), then migrateTestDatabase() reapplies everything
     * missing from the `migrations` table in filename order.
     */
    public function test_s11_migrations_roll_back_and_reapply_cleanly(): void
    {
        $placementPermissionsBefore = (int) $this->scalar(
            "select count(*) from security.permissions where code in ('hr.organizational_placement_periods.view', 'hr.organizational_placement_periods.record')"
        );
        $this->assertSame(2, $placementPermissionsBefore, 'fixture assumption: the S11 permission seed already ran');

        foreach (array_reverse(self::S11_MIGRATIONS) as $path) {
            DB::transaction(function () use ($path): void {
                $this->migration($path)->down();
                $migrationName = pathinfo($path, PATHINFO_FILENAME);
                DB::table('migrations')->where('migration', $migrationName)->delete();
            });
        }

        $this->assertSame(0, (int) $this->scalar(
            "select count(*) from information_schema.tables where table_schema = 'hr' and table_name = 'organizational_placement_periods'"
        ), 'down() must drop the S11 table itself, not just its rows');
        $this->assertSame(0, (int) $this->scalar(
            "select count(*) from security.permissions where code in ('hr.organizational_placement_periods.view', 'hr.organizational_placement_periods.record')"
        ), 'the S11 permission seed rows must be gone');

        // S01–S10, S12, S14, and S16 objects the S11 migrations never touched must survive
        // untouched. The count below is S09's five permissions plus S10's two plus S12's three plus
        // S14's one plus S16's three (14, not 7, 10, or 11) — S11's rollback here never touches
        // hr.full_secondment_periods or hr.workplace_assignment_periods, since both tables'
        // RESTRICT FKs point at hr.employment_relationships and org.organizational_units, neither
        // of which is hr.organizational_placement_periods, and S12/S16 spec §7.2/§S16.8 make
        // "actual workplace" a derived read-time query rather than a persisted reference to it;
        // S14 adds no table of its own at all (§16), only the one permission row counted here.
        $this->assertSame(1, (int) $this->scalar(
            "select count(*) from information_schema.tables where table_schema = 'hr' and table_name = 'employment_status_periods'"
        ), "hr.employment_status_periods (S10) must still exist, untouched by S11's rollback");
        $this->assertSame(1, (int) $this->scalar(
            "select count(*) from information_schema.tables where table_schema = 'org' and table_name = 'organizational_units'"
        ), "org.organizational_units (S07) must still exist, untouched by S11's rollback");
        $this->assertSame(1, (int) $this->scalar(
            "select count(*) from information_schema.tables where table_schema = 'hr' and table_name = 'full_secondment_periods'"
        ), "hr.full_secondment_periods (S12) must still exist, untouched by S11's rollback");
        $this->assertSame(1, (int) $this->scalar(
            "select count(*) from information_schema.tables where table_schema = 'hr' and table_name = 'workplace_assignment_periods'"
        ), "hr.workplace_assignment_periods (S16) must still exist, untouched by S11's rollback");
        // 22: plus S20's, S21's, S22's and S23's two permissions each.
        $this->assertSame(25, (int) $this->scalar("select count(*) from security.permissions where module = 'human_resources' and code not like '%organizational_placement_periods%'"));

        $this->migrateTestDatabase();

        $this->assertSame($placementPermissionsBefore, (int) $this->scalar(
            "select count(*) from security.permissions where code in ('hr.organizational_placement_periods.view', 'hr.organizational_placement_periods.record')"
        ));
        $this->assertSame(0, (int) $this->scalar('select count(*) from hr.organizational_placement_periods'), 'S11 seeds zero organizational_placement_periods rows');
    }

    /**
     * S12 spec §19/§24 ("migration rollback/reapply"): round-trips both S12 migrations directly,
     * the same batch-independent way test_s11_migrations_roll_back_and_reapply_cleanly() exercises
     * S11's — down() in reverse order (permission seed, then the table itself), each wrapped in
     * its own DB::transaction(), then migrateTestDatabase() reapplies everything missing from the
     * `migrations` table in filename order.
     */
    public function test_s12_migrations_roll_back_and_reapply_cleanly(): void
    {
        $secondmentPermissionsBefore = (int) $this->scalar(
            "select count(*) from security.permissions where code in ('hr.full_secondment_periods.view', 'hr.full_secondment_periods.start', 'hr.full_secondment_periods.end')"
        );
        $this->assertSame(3, $secondmentPermissionsBefore, 'fixture assumption: the S12 permission seed already ran');

        foreach (array_reverse(self::S12_MIGRATIONS) as $path) {
            DB::transaction(function () use ($path): void {
                $this->migration($path)->down();
                $migrationName = pathinfo($path, PATHINFO_FILENAME);
                DB::table('migrations')->where('migration', $migrationName)->delete();
            });
        }

        $this->assertSame(0, (int) $this->scalar(
            "select count(*) from information_schema.tables where table_schema = 'hr' and table_name = 'full_secondment_periods'"
        ), 'down() must drop the S12 table itself, not just its rows');
        $this->assertSame(0, (int) $this->scalar(
            "select count(*) from security.permissions where code in ('hr.full_secondment_periods.view', 'hr.full_secondment_periods.start', 'hr.full_secondment_periods.end')"
        ), 'the S12 permission seed rows must be gone');

        // S01–S11, S14, and S16 objects the S12 migrations never touched must survive untouched.
        $this->assertSame(1, (int) $this->scalar(
            "select count(*) from information_schema.tables where table_schema = 'hr' and table_name = 'organizational_placement_periods'"
        ), "hr.organizational_placement_periods (S11) must still exist, untouched by S12's rollback");
        $this->assertSame(1, (int) $this->scalar(
            "select count(*) from information_schema.tables where table_schema = 'hr' and table_name = 'employment_relationships'"
        ), "hr.employment_relationships (S09) must still exist, untouched by S12's rollback");
        $this->assertSame(1, (int) $this->scalar(
            "select count(*) from information_schema.tables where table_schema = 'org' and table_name = 'organizational_units'"
        ), "org.organizational_units (S07) must still exist, untouched by S12's rollback");
        // S16's hr.workplace_assignment_periods carries no FK to hr.full_secondment_periods (S16
        // spec §S16.8: the two movement mechanisms are mutually exclusive at write time, not
        // linked by any FK), so it survives S12's rollback untouched too.
        $this->assertSame(1, (int) $this->scalar(
            "select count(*) from information_schema.tables where table_schema = 'hr' and table_name = 'workplace_assignment_periods'"
        ), "hr.workplace_assignment_periods (S16) must still exist, untouched by S12's rollback");
        // S14 adds no table of its own at all (§16), only the one permission row counted here — the
        // total below (13, not 9 or 10) is S09's five permissions plus S10's two plus S11's two
        // plus S14's one plus S16's three.
        // 21: the 13 above plus S20's, S21's, S22's and S23's two permissions each.
        $this->assertSame(24, (int) $this->scalar("select count(*) from security.permissions where module = 'human_resources' and code not like '%full_secondment_periods%'"));

        $this->migrateTestDatabase();

        $this->assertSame($secondmentPermissionsBefore, (int) $this->scalar(
            "select count(*) from security.permissions where code in ('hr.full_secondment_periods.view', 'hr.full_secondment_periods.start', 'hr.full_secondment_periods.end')"
        ));
        $this->assertSame(0, (int) $this->scalar('select count(*) from hr.full_secondment_periods'), 'S12 seeds zero full_secondment_periods rows');
    }

    /**
     * ADR-S14-002 test requirement 5 ("rollback/reapply works") and
     * docs/transfer-foundation-specification.md §16/§21 ("migration-safe and rollback-safe"):
     * round-trips both S14 migrations directly, the same batch-independent way
     * test_s12_migrations_roll_back_and_reapply_cleanly() exercises S12's — down() in reverse order
     * (permission seed, then the decision-type seed), each wrapped in its own DB::transaction(),
     * then migrateTestDatabase() reapplies everything missing from the `migrations` table. Unlike
     * every S07–S12 test in this file, no table-existence assertion is needed here (§16 — S14
     * creates no table), so this test is scoped entirely to row content: the one ref.decision_types
     * row and the one security.permissions row this stage seeds.
     */
    public function test_s14_migrations_roll_back_and_reapply_cleanly(): void
    {
        $transferPermissionsBefore = (int) $this->scalar(
            "select count(*) from security.permissions where code = 'hr.employment_relationships.transfer'"
        );
        $decisionTypesBefore = (int) $this->scalar("select count(*) from ref.decision_types where code = 'TRANSFER'");
        $this->assertSame(1, $transferPermissionsBefore, 'fixture assumption: the S14 permission seed already ran');
        $this->assertSame(1, $decisionTypesBefore, 'fixture assumption: the S14 decision-type seed already ran');

        foreach (array_reverse(self::S14_MIGRATIONS) as $path) {
            DB::transaction(function () use ($path): void {
                $this->migration($path)->down();
                $migrationName = pathinfo($path, PATHINFO_FILENAME);
                DB::table('migrations')->where('migration', $migrationName)->delete();
            });
        }

        $this->assertSame(0, (int) $this->scalar(
            "select count(*) from security.permissions where code = 'hr.employment_relationships.transfer'"
        ), 'the S14 permission seed row must be gone');
        $this->assertSame(0, (int) $this->scalar(
            "select count(*) from ref.decision_types where code = 'TRANSFER'"
        ), 'the S14 decision-type seed row must be gone');
        // ADR-S14-002 test requirement 10 ("no Transfer subtype was seeded") holds trivially once
        // the table is empty of this stage's content, but is also asserted directly in
        // tests/Feature/HumanResources/TransferFoundationTest.php against the normal migrated state.

        // S01–S13 objects the S14 migrations never touched must survive untouched.
        $this->assertSame(1, (int) $this->scalar(
            "select count(*) from information_schema.tables where table_schema = 'ref' and table_name = 'decision_types'"
        ), 'ref.decision_types (S05) itself must still exist, untouched by S14\'s rollback — only its TRANSFER row is gone');
        $this->assertGreaterThan(0, (int) $this->scalar(
            "select count(*) from ref.employment_categories where code like 'grade_%'"
        ), 'ref.employment_categories (S13) must still exist, untouched by S14\'s rollback');

        $this->migrateTestDatabase();

        $this->assertSame($transferPermissionsBefore, (int) $this->scalar(
            "select count(*) from security.permissions where code = 'hr.employment_relationships.transfer'"
        ));
        $this->assertSame($decisionTypesBefore, (int) $this->scalar("select count(*) from ref.decision_types where code = 'TRANSFER'"));
        $this->assertSame('نقل', (string) $this->scalar("select name_ar from ref.decision_types where code = 'TRANSFER'"));
        $this->assertTrue((bool) $this->scalar("select is_active from ref.decision_types where code = 'TRANSFER'"));
    }

    /**
     * ADR-S16-001 §26 ("migration lifecycle PASS") and
     * docs/workplace-assignment-foundation-specification.md §S16.22: round-trips all three S16
     * migrations directly, the same batch-independent way test_s12_migrations_roll_back_and_
     * reapply_cleanly() exercises S12's — down() in reverse order (permission seed, then the
     * decision-type seed, then the table itself), each wrapped in its own DB::transaction(), then
     * migrateTestDatabase() reapplies everything missing from the `migrations` table in filename
     * order. Unlike S14 (no table) and like S12 (a table), this test asserts both table existence
     * and row content, since S16 is a hybrid of the two shapes: a dedicated temporal table (§18)
     * plus a decision-type seed row (§14).
     */
    public function test_s16_migrations_roll_back_and_reapply_cleanly(): void
    {
        $assignmentPermissionsBefore = (int) $this->scalar(
            "select count(*) from security.permissions where code in ('hr.workplace_assignment_periods.view', 'hr.workplace_assignment_periods.start', 'hr.workplace_assignment_periods.end')"
        );
        $decisionTypesBefore = (int) $this->scalar("select count(*) from ref.decision_types where code = 'ASSIGNMENT'");
        $this->assertSame(3, $assignmentPermissionsBefore, 'fixture assumption: the S16 permission seed already ran');
        $this->assertSame(1, $decisionTypesBefore, 'fixture assumption: the S16 decision-type seed already ran');

        foreach (array_reverse(self::S16_MIGRATIONS) as $path) {
            DB::transaction(function () use ($path): void {
                $this->migration($path)->down();
                $migrationName = pathinfo($path, PATHINFO_FILENAME);
                DB::table('migrations')->where('migration', $migrationName)->delete();
            });
        }

        $this->assertSame(0, (int) $this->scalar(
            "select count(*) from information_schema.tables where table_schema = 'hr' and table_name = 'workplace_assignment_periods'"
        ), 'down() must drop the S16 table itself, not just its rows');
        $this->assertSame(0, (int) $this->scalar(
            "select count(*) from security.permissions where code in ('hr.workplace_assignment_periods.view', 'hr.workplace_assignment_periods.start', 'hr.workplace_assignment_periods.end')"
        ), 'the S16 permission seed rows must be gone');
        $this->assertSame(0, (int) $this->scalar(
            "select count(*) from ref.decision_types where code = 'ASSIGNMENT'"
        ), 'the S16 decision-type seed row must be gone');
        // ADR-S16-001 §14 ("No assignment subtypes") holds trivially once the table is empty of
        // this stage's content, but is also asserted directly in
        // tests/Feature/HumanResources/WorkplaceAssignmentFoundationTest.php against the normal
        // migrated state.

        // S01–S15 objects the S16 migrations never touched must survive untouched.
        $this->assertSame(1, (int) $this->scalar(
            "select count(*) from information_schema.tables where table_schema = 'ref' and table_name = 'decision_types'"
        ), 'ref.decision_types (S05) itself must still exist, untouched by S16\'s rollback — only its ASSIGNMENT row is gone');
        $this->assertSame(1, (int) $this->scalar(
            "select count(*) from ref.decision_types where code = 'TRANSFER'"
        ), 'TRANSFER (S14) must still exist, untouched by S16\'s rollback');
        $this->assertSame(1, (int) $this->scalar(
            "select count(*) from information_schema.tables where table_schema = 'hr' and table_name = 'employment_relationships'"
        ), "hr.employment_relationships (S09) must still exist, untouched by S16's rollback");
        $this->assertSame(1, (int) $this->scalar(
            "select count(*) from information_schema.tables where table_schema = 'org' and table_name = 'organizational_units'"
        ), "org.organizational_units (S07) must still exist, untouched by S16's rollback");
        $this->assertSame(1, (int) $this->scalar(
            "select count(*) from information_schema.tables where table_schema = 'hr' and table_name = 'full_secondment_periods'"
        ), "hr.full_secondment_periods (S12) must still exist, untouched by S16's rollback");
        $this->assertSame(1, (int) $this->scalar(
            "select count(*) from information_schema.tables where table_schema = 'hr' and table_name = 'organizational_placement_periods'"
        ), "hr.organizational_placement_periods (S11) must still exist, untouched by S16's rollback");

        $this->migrateTestDatabase();

        $this->assertSame($assignmentPermissionsBefore, (int) $this->scalar(
            "select count(*) from security.permissions where code in ('hr.workplace_assignment_periods.view', 'hr.workplace_assignment_periods.start', 'hr.workplace_assignment_periods.end')"
        ));
        $this->assertSame($decisionTypesBefore, (int) $this->scalar("select count(*) from ref.decision_types where code = 'ASSIGNMENT'"));
        $this->assertSame('تكليف', (string) $this->scalar("select name_ar from ref.decision_types where code = 'ASSIGNMENT'"));
        $this->assertTrue((bool) $this->scalar("select is_active from ref.decision_types where code = 'ASSIGNMENT'"));
        $this->assertSame(0, (int) $this->scalar('select count(*) from hr.workplace_assignment_periods'), 'S16 seeds zero workplace_assignment_periods rows');
    }

    /**
     * S20 spec §S20.20 ("migration rollback/reapply"): round-trips both S20 migrations directly,
     * the same batch-independent way test_s16_migrations_roll_back_and_reapply_cleanly() exercises
     * S16's — down() in reverse order (permission seed, then the table itself), each wrapped in its
     * own DB::transaction(), then migrateTestDatabase() reapplies everything missing from the
     * `migrations` table in filename order. Proves the migration is additive: every S01–S19 object
     * (including the S13 seven-grade catalog content it references) survives the rollback.
     */
    public function test_s20_migrations_roll_back_and_reapply_cleanly(): void
    {
        $permissionCodes = "('hr.employment_category_periods.view', 'hr.employment_category_periods.record')";
        $categoryPermissionsBefore = (int) $this->scalar("select count(*) from security.permissions where code in {$permissionCodes}");
        $this->assertSame(2, $categoryPermissionsBefore, 'fixture assumption: the S20 permission seed already ran');

        foreach (array_reverse(self::S20_MIGRATIONS) as $path) {
            DB::transaction(function () use ($path): void {
                $this->migration($path)->down();
                $migrationName = pathinfo($path, PATHINFO_FILENAME);
                DB::table('migrations')->where('migration', $migrationName)->delete();
            });
        }

        $this->assertSame(0, (int) $this->scalar(
            "select count(*) from information_schema.tables where table_schema = 'hr' and table_name = 'employment_category_periods'"
        ), 'down() must drop the S20 table itself, not just its rows');
        $this->assertSame(0, (int) $this->scalar("select count(*) from security.permissions where code in {$permissionCodes}"), 'the S20 permission seed rows must be gone');

        // S01–S19 objects the S20 migrations never touched must survive untouched.
        $this->assertSame(7, (int) $this->scalar(
            "select count(*) from ref.employment_categories where code like 'grade_%'"
        ), 'ref.employment_categories (S05/S13) and its seven grades must survive S20\'s rollback untouched');
        foreach (['employment_relationships', 'employment_status_periods', 'organizational_placement_periods', 'full_secondment_periods', 'workplace_assignment_periods'] as $table) {
            $this->assertSame(1, (int) $this->scalar(
                "select count(*) from information_schema.tables where table_schema = 'hr' and table_name = ?", [$table]
            ), "hr.{$table} must still exist, untouched by S20's rollback");
        }
        // 22: the 16 pre-S20 hr permissions plus S21's, S22's and S23's two each.
        $this->assertSame(25, (int) $this->scalar("select count(*) from security.permissions where module = 'human_resources'"), 'every non-S20 hr permission survives');

        $this->migrateTestDatabase();

        $this->assertSame($categoryPermissionsBefore, (int) $this->scalar("select count(*) from security.permissions where code in {$permissionCodes}"));
        $this->assertSame(0, (int) $this->scalar('select count(*) from hr.employment_category_periods'), 'S20 seeds zero employment_category_periods rows');
        $this->assertSame(1, (int) $this->scalar(
            "select count(*) from pg_constraint where conname = 'employment_category_periods_no_overlap' and contype = 'x'"
        ), 'the GiST exclusion constraint is restored by reapplying the migration');
    }

    /**
     * S21 spec §S21.21 ("migration rollback/reapply"): round-trips both S21 migrations directly,
     * the same batch-independent way test_s20_migrations_roll_back_and_reapply_cleanly() does.
     * Proves the migration is additive: every S01–S20 object survives the rollback, including
     * ref.contract_types itself (and its zero seeded rows — S21 seeds no contract type).
     */
    public function test_s21_migrations_roll_back_and_reapply_cleanly(): void
    {
        $permissionCodes = "('hr.employment_contract_periods.view', 'hr.employment_contract_periods.record')";
        $contractPermissionsBefore = (int) $this->scalar("select count(*) from security.permissions where code in {$permissionCodes}");
        $contractTypesBefore = (int) $this->scalar('select count(*) from ref.contract_types');
        $this->assertSame(2, $contractPermissionsBefore, 'fixture assumption: the S21 permission seed already ran');

        foreach (array_reverse(self::S21_MIGRATIONS) as $path) {
            DB::transaction(function () use ($path): void {
                $this->migration($path)->down();
                $migrationName = pathinfo($path, PATHINFO_FILENAME);
                DB::table('migrations')->where('migration', $migrationName)->delete();
            });
        }

        $this->assertSame(0, (int) $this->scalar(
            "select count(*) from information_schema.tables where table_schema = 'hr' and table_name = 'employment_contract_periods'"
        ), 'down() must drop the S21 table itself, not just its rows');
        $this->assertSame(0, (int) $this->scalar("select count(*) from security.permissions where code in {$permissionCodes}"), 'the S21 permission seed rows must be gone');

        $this->assertSame(1, (int) $this->scalar(
            "select count(*) from information_schema.tables where table_schema = 'ref' and table_name = 'contract_types'"
        ), 'ref.contract_types (S05/S13) must survive S21\'s rollback untouched');
        $this->assertSame($contractTypesBefore, (int) $this->scalar('select count(*) from ref.contract_types'));
        foreach (['employment_relationships', 'employment_status_periods', 'employment_category_periods', 'workplace_assignment_periods'] as $table) {
            $this->assertSame(1, (int) $this->scalar(
                "select count(*) from information_schema.tables where table_schema = 'hr' and table_name = ?", [$table]
            ), "hr.{$table} must still exist, untouched by S21's rollback");
        }
        // 22: every hr permission except S21's own two.
        $this->assertSame(25, (int) $this->scalar("select count(*) from security.permissions where module = 'human_resources'"), 'every non-S21 hr permission survives');

        $this->migrateTestDatabase();

        $this->assertSame($contractPermissionsBefore, (int) $this->scalar("select count(*) from security.permissions where code in {$permissionCodes}"));
        $this->assertSame(0, (int) $this->scalar('select count(*) from hr.employment_contract_periods'), 'S21 seeds zero employment_contract_periods rows');
        $this->assertSame(1, (int) $this->scalar(
            "select count(*) from pg_constraint where conname = 'employment_contract_periods_no_overlap' and contype = 'x'"
        ), 'the GiST exclusion constraint is restored by reapplying the migration');
    }

    /**
     * S22 spec §S22.21 ("migration rollback/reapply"): round-trips both S22 migrations directly,
     * the same batch-independent way as S20/S21. Proves the migration is additive: every S01–S21
     * object survives, including ref.job_titles (and its zero seeded rows — S22 seeds no title) and
     * the S06 job_title_administrator_classifications mapping.
     */
    public function test_s22_migrations_roll_back_and_reapply_cleanly(): void
    {
        $permissionCodes = "('hr.employment_job_title_periods.view', 'hr.employment_job_title_periods.record')";
        $before = (int) $this->scalar("select count(*) from security.permissions where code in {$permissionCodes}");
        $jobTitlesBefore = (int) $this->scalar('select count(*) from ref.job_titles');
        $this->assertSame(2, $before, 'fixture assumption: the S22 permission seed already ran');

        foreach (array_reverse(self::S22_MIGRATIONS) as $path) {
            DB::transaction(function () use ($path): void {
                $this->migration($path)->down();
                $migrationName = pathinfo($path, PATHINFO_FILENAME);
                DB::table('migrations')->where('migration', $migrationName)->delete();
            });
        }

        $this->assertSame(0, (int) $this->scalar(
            "select count(*) from information_schema.tables where table_schema = 'hr' and table_name = 'employment_job_title_periods'"
        ), 'down() must drop the S22 table itself');
        $this->assertSame(0, (int) $this->scalar("select count(*) from security.permissions where code in {$permissionCodes}"));
        foreach (['ref' => ['job_titles', 'job_title_administrator_classifications', 'supervisory_titles'], 'hr' => ['employment_relationships', 'employment_category_periods', 'employment_contract_periods']] as $schema => $tables) {
            foreach ($tables as $table) {
                $this->assertSame(1, (int) $this->scalar(
                    'select count(*) from information_schema.tables where table_schema = ? and table_name = ?', [$schema, $table]
                ), "{$schema}.{$table} must survive S22's rollback untouched");
            }
        }
        $this->assertSame($jobTitlesBefore, (int) $this->scalar('select count(*) from ref.job_titles'));
        // 22: every hr permission except S22's own two (S23's are independent of S22's table).
        $this->assertSame(25, (int) $this->scalar("select count(*) from security.permissions where module = 'human_resources'"), 'every non-S22 hr permission survives');

        $this->migrateTestDatabase();

        $this->assertSame($before, (int) $this->scalar("select count(*) from security.permissions where code in {$permissionCodes}"));
        $this->assertSame(0, (int) $this->scalar('select count(*) from hr.employment_job_title_periods'), 'S22 seeds zero rows');
        $this->assertSame(1, (int) $this->scalar(
            "select count(*) from pg_constraint where conname = 'employment_job_title_periods_no_overlap' and contype = 'x'"
        ));
    }

    /**
     * S23 spec §S23.20 ("migration rollback/reapply"): round-trips both S23 migrations directly, the
     * same batch-independent way as S20–S22. Proves the migration is additive: hr.persons and the
     * (still empty) ref.academic_degrees / ref.qualification_types / ref.specialties catalogs survive.
     */
    public function test_s23_migrations_roll_back_and_reapply_cleanly(): void
    {
        $permissionCodes = "('hr.person_qualifications.view', 'hr.person_qualifications.record')";
        $before = (int) $this->scalar("select count(*) from security.permissions where code in {$permissionCodes}");
        $this->assertSame(2, $before, 'fixture assumption: the S23 permission seed already ran');

        foreach (array_reverse(self::S23_MIGRATIONS) as $path) {
            DB::transaction(function () use ($path): void {
                $this->migration($path)->down();
                $migrationName = pathinfo($path, PATHINFO_FILENAME);
                DB::table('migrations')->where('migration', $migrationName)->delete();
            });
        }

        $this->assertSame(0, (int) $this->scalar(
            "select count(*) from information_schema.tables where table_schema = 'hr' and table_name = 'person_qualifications'"
        ), 'down() must drop the S23 table itself');
        $this->assertSame(0, (int) $this->scalar("select count(*) from security.permissions where code in {$permissionCodes}"));
        foreach (['ref' => ['academic_degrees', 'qualification_types', 'specialties'], 'hr' => ['persons', 'employment_relationships']] as $schema => $tables) {
            foreach ($tables as $table) {
                $this->assertSame(1, (int) $this->scalar(
                    'select count(*) from information_schema.tables where table_schema = ? and table_name = ?', [$schema, $table]
                ), "{$schema}.{$table} must survive S23's rollback untouched");
            }
        }
        $this->assertSame(25, (int) $this->scalar("select count(*) from security.permissions where module = 'human_resources'"), 'every non-S23 hr permission survives');

        $this->migrateTestDatabase();

        $this->assertSame($before, (int) $this->scalar("select count(*) from security.permissions where code in {$permissionCodes}"));
        $this->assertSame(0, (int) $this->scalar('select count(*) from hr.person_qualifications'), 'S23 seeds zero rows');
        $this->assertSame(1, (int) $this->scalar(
            "select count(*) from pg_constraint where conname = 'person_qualifications_identity_unique' and contype = 'u'"
        ));
    }

    /**
     * S24 spec §S24.20 ("migration rollback/reapply", legacy compatibility): a Person row that
     * predates S24 survives the round trip, and re-applying S24 leaves every profile column NULL —
     * the migration never fabricates a name, gender, marital status, birth date or birth place.
     */
    public function test_s24_migrations_roll_back_and_reapply_cleanly_with_legacy_persons(): void
    {
        $this->assertSame(1, (int) $this->scalar("select count(*) from security.permissions where code = 'hr.persons.update_profile'"), 'fixture assumption: the S24 permission seed already ran');

        $legacyId = (string) Str::uuid();
        DB::table('hr.persons')->insert(['id' => $legacyId, 'national_id' => 'S24-LEGACY-PROBE-1', 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);

        try {
            foreach (array_reverse(self::S24_MIGRATIONS) as $path) {
                DB::transaction(function () use ($path): void {
                    $this->migration($path)->down();
                    $migrationName = pathinfo($path, PATHINFO_FILENAME);
                    DB::table('migrations')->where('migration', $migrationName)->delete();
                });
            }

            $columns = DB::table('information_schema.columns')->where('table_schema', 'hr')->where('table_name', 'persons')->pluck('column_name')->all();
            foreach (['full_name_ar', 'gender_id', 'marital_status_id', 'birth_date', 'birth_place'] as $column) {
                $this->assertNotContains($column, $columns, "down() must drop {$column}");
            }
            $this->assertSame(0, (int) $this->scalar("select count(*) from security.permissions where code = 'hr.persons.update_profile'"));
            $this->assertSame(1, (int) $this->scalar('select count(*) from hr.persons where id = ?', [$legacyId]), 'the legacy person survives S24 rollback');
            $this->assertSame(26, (int) $this->scalar("select count(*) from security.permissions where module = 'human_resources'"), 'every non-S24 hr permission survives');

            $this->migrateTestDatabase();

            $row = DB::table('hr.persons')->where('id', $legacyId)->first();
            $this->assertNotNull($row);
            $this->assertSame('S24-LEGACY-PROBE-1', $row->national_id);
            foreach (['full_name_ar', 'gender_id', 'marital_status_id', 'birth_date', 'birth_place'] as $column) {
                $this->assertNull($row->{$column}, "legacy {$column} stays NULL — never fabricated");
            }
            $this->assertSame(1, (int) $this->scalar("select count(*) from security.permissions where code = 'hr.persons.update_profile'"));
            $this->assertSame(2, (int) $this->scalar(
                "select count(*) from pg_constraint where conname in ('persons_gender_fk', 'persons_marital_status_fk') and contype = 'f'"
            ));
        } finally {
            DB::table('hr.persons')->where('id', $legacyId)->delete();
        }
    }

    /**
     * S26 spec §S26.20 ("migration rollback/reapply"): round-trips both S26 migrations directly, the
     * same batch-independent way as S20–S24. Proves the migration is additive: ref.specialties (and
     * its zero seeded rows — S26 seeds nothing), the S06 specialty→cadre mapping, and every
     * employment stream survive.
     */
    public function test_s26_migrations_roll_back_and_reapply_cleanly(): void
    {
        $permissionCodes = "('hr.employment_specialty_periods.view', 'hr.employment_specialty_periods.record')";
        $before = (int) $this->scalar("select count(*) from security.permissions where code in {$permissionCodes}");
        $specialtiesBefore = (int) $this->scalar('select count(*) from ref.specialties');
        $this->assertSame(2, $before, 'fixture assumption: the S26 permission seed already ran');

        foreach (array_reverse(self::S26_MIGRATIONS) as $path) {
            DB::transaction(function () use ($path): void {
                $this->migration($path)->down();
                $migrationName = pathinfo($path, PATHINFO_FILENAME);
                DB::table('migrations')->where('migration', $migrationName)->delete();
            });
        }

        $this->assertSame(0, (int) $this->scalar(
            "select count(*) from information_schema.tables where table_schema = 'hr' and table_name = 'employment_specialty_periods'"
        ), 'down() must drop the S26 table itself');
        $this->assertSame(0, (int) $this->scalar("select count(*) from security.permissions where code in {$permissionCodes}"));
        foreach (['ref' => ['specialties', 'specialty_cadre_category_mappings', 'monthly_cadre_categories'], 'hr' => ['employment_relationships', 'employment_job_title_periods', 'person_qualifications']] as $schema => $tables) {
            foreach ($tables as $table) {
                $this->assertSame(1, (int) $this->scalar(
                    'select count(*) from information_schema.tables where table_schema = ? and table_name = ?', [$schema, $table]
                ), "{$schema}.{$table} must survive S26's rollback untouched");
            }
        }
        $this->assertSame($specialtiesBefore, (int) $this->scalar('select count(*) from ref.specialties'));
        $this->assertSame(25, (int) $this->scalar("select count(*) from security.permissions where module = 'human_resources'"), 'every non-S26 hr permission survives');

        $this->migrateTestDatabase();

        $this->assertSame($before, (int) $this->scalar("select count(*) from security.permissions where code in {$permissionCodes}"));
        $this->assertSame(0, (int) $this->scalar('select count(*) from hr.employment_specialty_periods'), 'S26 seeds zero rows');
        $this->assertSame(1, (int) $this->scalar(
            "select count(*) from pg_constraint where conname = 'employment_specialty_periods_no_overlap' and contype = 'x'"
        ));
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
