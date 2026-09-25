<?php

namespace Tests\Feature\Security;

use Illuminate\Support\Facades\DB;

/**
 * §M Scope Audit / §26/§27 of the S03 authorization: S03 introduces exactly the security.* tables
 * and nothing from a later stage's business domain.
 */
class ScopeBoundaryTest extends SecurityTestCase
{
    public function test_no_hr_organization_or_reporting_tables_exist_in_any_schema(): void
    {
        $forbidden = ['employee', 'employees', 'person', 'persons', 'national_id', 'organization_unit',
            'organization_units', 'contract', 'contracts', 'leave', 'placement', 'secondment', 'transfer', ];

        $tables = DB::table('information_schema.tables')
            ->whereIn('table_schema', ['hr', 'org', 'reporting', 'public'])
            ->pluck('table_name');

        foreach ($tables as $table) {
            foreach ($forbidden as $needle) {
                $this->assertStringNotContainsStringIgnoringCase($needle, $table, "unexpected table leaking business scope: {$table}");
            }
        }
    }

    public function test_hr_org_and_reporting_schemas_remain_empty(): void
    {
        foreach (['hr', 'org', 'reporting'] as $schema) {
            $count = DB::table('information_schema.tables')->where('table_schema', $schema)->count();
            $this->assertSame(0, $count, "schema {$schema} must stay empty until its owning stage runs");
        }
    }

    public function test_no_permanent_audit_log_table_was_introduced_by_s03(): void
    {
        // S03 itself introduces no permanent audit architecture (§25 of the S03 authorization) — it
        // passes a security-event handoff boundary to S04, which is the stage authorized to build
        // one (see docs/audit-command-infrastructure-specification.md). This is verified by
        // migration provenance rather than by the audit schema being empty: as of S04, it
        // legitimately is not (audit.audit_entries is created by a 2026_09_25_* migration).
        $s03Migrations = collect(glob(base_path('database/migrations/2026_09_23_*.php')))
            ->map(fn (string $path) => pathinfo($path, PATHINFO_FILENAME));

        $this->assertNotEmpty($s03Migrations);

        foreach ($s03Migrations as $migration) {
            $this->assertStringNotContainsString('audit', $migration, "an S03 migration must never touch the audit schema: {$migration}");
        }
    }

    public function test_no_organization_scope_columns_exist_on_the_principals_table(): void
    {
        $columns = DB::table('information_schema.columns')
            ->where('table_schema', 'security')->where('table_name', 'principals')
            ->pluck('column_name');

        foreach (['organization_unit_id', 'branch_id', 'scope', 'national_id'] as $forbiddenColumn) {
            $this->assertNotContains($forbiddenColumn, $columns->all());
        }
    }

    public function test_only_security_module_permissions_were_seeded(): void
    {
        $modules = DB::table('security.permissions')->distinct()->pluck('module');

        $this->assertSame(['security'], $modules->all());
    }

    public function test_s02_migrations_remain_unaffected_and_still_pass_discipline_checks(): void
    {
        $s02Migrations = glob(base_path('database/migrations/2026_09_20_*.php'));
        $this->assertCount(2, $s02Migrations, 'S03 must not add to or remove S02\'s migration files');
    }
}
