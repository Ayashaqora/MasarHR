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
        // S03 may pass a security-event handoff boundary to S04, but must not build a permanent
        // audit architecture itself (§25 of the S03 authorization).
        $count = DB::table('information_schema.tables')->where('table_schema', 'audit')->count();
        $this->assertSame(0, $count);
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
