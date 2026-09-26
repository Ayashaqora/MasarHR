<?php

namespace Tests\Feature\Organization;

use Illuminate\Support\Facades\DB;

/**
 * S07 scope audit (docs/organization-hierarchy-foundation-specification.md §33/§34): the org
 * schema contains exactly the one S07-authorized table and nothing else; no organizational-scope
 * column (S08's subject matter) exists anywhere; no Person/Employee/Employment content leaked in;
 * no hard delete is exposed; no generic Command-Bus/CRUD-service/repository/Unit-of-Work
 * infrastructure was introduced.
 */
class ScopeBoundaryTest extends OrganizationTestCase
{
    public function test_org_schema_contains_exactly_the_one_authorized_table(): void
    {
        $tables = DB::table('information_schema.tables')->where('table_schema', 'org')->pluck('table_name')->all();

        $this->assertSame(['organizational_units'], $tables);
    }

    public function test_organizational_units_has_no_type_level_code_or_temporal_column(): void
    {
        // Spec §11 (no type/level/code) and §16 (no temporal model) — every column beyond the
        // core identity/lifecycle/concurrency shape would be an invented business rule.
        $columns = DB::table('information_schema.columns')
            ->where('table_schema', 'org')->where('table_name', 'organizational_units')
            ->pluck('column_name')->all();

        sort($columns);
        $this->assertSame(
            ['created_at', 'id', 'is_active', 'name', 'parent_id', 'updated_at', 'version'],
            $columns,
        );
    }

    public function test_no_organizational_scope_column_exists_anywhere_s08_not_implemented(): void
    {
        // S08 ("Organizational Access Scope") is explicitly not this stage's subject matter
        // (spec §28). No table anywhere may carry a subtree-visibility column yet.
        $offendingTables = DB::table('information_schema.columns')
            ->whereIn('column_name', ['organizational_unit_id', 'organization_scope', 'org_unit_id', 'branch_id'])
            ->pluck('table_name')->all();

        $this->assertSame([], $offendingTables, 'no S08 organizational-scope column may exist yet');
    }

    public function test_no_hr_person_employee_or_transaction_tables_leaked_in_via_s07(): void
    {
        $forbidden = ['employee', 'employees', 'person', 'persons', 'national_id',
            'contract_record', 'leave_request', 'secondment', 'transfer', 'reappointment', ];

        $tables = DB::table('information_schema.tables')
            ->whereIn('table_schema', ['ref', 'hr', 'org', 'reporting', 'public'])
            ->pluck('table_name');

        foreach ($tables as $table) {
            foreach ($forbidden as $needle) {
                $this->assertStringNotContainsStringIgnoringCase($needle, $table, "unexpected table leaking business scope: {$table}");
            }
        }
    }

    public function test_no_generic_orchestration_or_crud_infrastructure_classes_exist(): void
    {
        $forbidden = [
            'app/Modules/Organization/Application/CommandBus.php',
            'app/Modules/Organization/Application/GenericCrudService.php',
            'app/Modules/Organization/Application/GenericRepository.php',
            'app/Modules/Organization/Application/UnitOfWork.php',
            'app/Modules/Organization/Infrastructure/Repository',
        ];

        foreach ($forbidden as $path) {
            $this->assertFileDoesNotExist(base_path($path), "forbidden-scope path exists: {$path}");
            $this->assertDirectoryDoesNotExist(base_path($path), "forbidden-scope path exists: {$path}");
        }
    }

    public function test_no_organization_route_exposes_a_hard_delete(): void
    {
        $routes = collect(app('router')->getRoutes())
            ->filter(fn ($route) => str_starts_with($route->uri(), 'api/v1/organization/'));

        $this->assertGreaterThan(0, $routes->count(), 'fixture assumption: organization routes are registered');
        $this->assertFalse(
            $routes->contains(fn ($route) => in_array('DELETE', $route->methods(), true)),
            'no S07 organization route may expose a hard delete (§9/§34)',
        );
    }

    public function test_every_organization_route_carries_a_permission_middleware(): void
    {
        $routes = collect(app('router')->getRoutes())
            ->filter(fn ($route) => str_starts_with($route->uri(), 'api/v1/organization/'));

        foreach ($routes as $route) {
            $hasPermissionMiddleware = collect($route->gatherMiddleware())
                ->contains(fn ($middleware) => str_starts_with($middleware, 'permission:'));

            $this->assertTrue($hasPermissionMiddleware, "route {$route->uri()} is missing a permission: middleware entry (spec §35 item 6)");
        }
    }

    public function test_no_reporting_or_frontend_content_was_added_for_s07(): void
    {
        // Spec §18 (no reporting-schema population) and §19 (backend/API only, no frontend UI).
        $reportingTables = DB::table('information_schema.tables')->where('table_schema', 'reporting')->count();
        $this->assertSame(0, $reportingTables);

        $this->assertDirectoryDoesNotExist(base_path('../frontend/src/features/organization'));
    }
}
