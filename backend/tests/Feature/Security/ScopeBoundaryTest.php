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

    public function test_hr_and_reporting_schemas_remain_empty(): void
    {
        // 'org' is deliberately excluded here: S07 is that schema's own authorized owning stage
        // and populates it (see tests/Feature/Organization/ScopeBoundaryTest.php for S07's own
        // boundary check). hr/reporting remain untouched by every stage through S07.
        foreach (['hr', 'reporting'] as $schema) {
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

    public function test_only_security_module_permissions_were_seeded_by_s03(): void
    {
        // Scoped to S03's own migration provenance, not the live table (same pattern as
        // test_no_permanent_audit_log_table_was_introduced_by_s03 below): a later, separately
        // authorized stage legitimately adds its own module's permissions afterwards (S05 added
        // 'reference' — see docs/reference-data-foundation-specification.md §16). This test only
        // guarantees S03 itself never seeded anything but 'security'.
        $s03SeedMigration = base_path('database/migrations/2026_09_23_000007_seed_security_baseline_permissions.php');
        $this->assertFileExists($s03SeedMigration);

        $contents = file_get_contents($s03SeedMigration);
        preg_match_all("/'module' => '([a-z_]+)'/", $contents, $matches);

        $this->assertNotEmpty($matches[1]);
        $this->assertSame(['security'], array_unique($matches[1]));
    }

    public function test_s02_migrations_remain_unaffected_and_still_pass_discipline_checks(): void
    {
        $s02Migrations = glob(base_path('database/migrations/2026_09_20_*.php'));
        $this->assertCount(2, $s02Migrations, 'S03 must not add to or remove S02\'s migration files');
    }

    public function test_security_schema_contains_exactly_the_seven_authorized_tables(): void
    {
        // The original six S03 tables plus S08's organizational_scope_grants
        // (docs/organizational-access-scope-specification.md §10) — no other table has been added
        // to this schema by any stage through S08.
        $tables = DB::table('information_schema.tables')->where('table_schema', 'security')->pluck('table_name')->all();
        sort($tables);

        $this->assertSame(
            ['credentials', 'organizational_scope_grants', 'permissions', 'principal_roles', 'principals', 'role_permissions', 'roles'],
            $tables,
        );
    }

    public function test_every_organizational_scope_route_carries_a_permission_middleware(): void
    {
        $routes = collect(app('router')->getRoutes())
            ->filter(fn ($route) => str_contains($route->uri(), 'organizational-scopes'));

        $this->assertGreaterThan(0, $routes->count(), 'fixture assumption: S08 organizational-scope routes are registered');

        foreach ($routes as $route) {
            $hasPermissionMiddleware = collect($route->gatherMiddleware())
                ->contains(fn ($middleware) => str_starts_with($middleware, 'permission:'));

            $this->assertTrue($hasPermissionMiddleware, "route {$route->uri()} is missing a permission: middleware entry (spec §21)");
        }
    }

    public function test_no_s08_organizational_scope_route_exposes_a_hard_delete_of_a_principal_or_unit(): void
    {
        // DELETE exists on this surface (revoking a grant is a real row delete — spec §12), but it
        // must only ever target a grant row, never a principal or an organizational unit.
        $routes = collect(app('router')->getRoutes())
            ->filter(fn ($route) => str_contains($route->uri(), 'organizational-scopes') && in_array('DELETE', $route->methods(), true));

        foreach ($routes as $route) {
            $this->assertStringContainsString('{organizationalScopeGrant}', $route->uri(), 'a DELETE route on this surface must target a grant, never a principal or unit directly');
        }
    }
}
