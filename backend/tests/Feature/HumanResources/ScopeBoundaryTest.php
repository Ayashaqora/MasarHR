<?php

namespace Tests\Feature\HumanResources;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

/**
 * S09 scope audit (docs/person-employment-foundation-specification.md §2/§17/§21/§24 P24): the
 * hr schema contains exactly the two S09-authorized tables and nothing else; neither table
 * carries an organizational-unit or name/demographic column; no S10+ out-of-scope concept
 * (transfer/secondment/leave/placement/status-history/reporting/…) leaked in via any route or
 * command; no hard delete is exposed; _to_delete/ is untouched. Mirrors
 * tests/Feature/Organization/ScopeBoundaryTest.php's and
 * tests/Feature/Reference/ScopeBoundaryTest.php's shape exactly.
 */
class ScopeBoundaryTest extends HumanResourcesTestCase
{
    private const FORBIDDEN_ROUTE_SEGMENTS = [
        'transfer', 'secondment', 'assignment', 'leave', 'qualification', 'placement',
        'work-schedule', 'workschedule', 'renewal', 'status-history', 'professional-history',
        'job-history', 'export', 'report',
    ];

    private const FORBIDDEN_COMMAND_NAMES = [
        'Transfer', 'Secondment', 'Assignment', 'Leave', 'ContractRenewal',
        'EmploymentStatusChange', 'ProfessionalHistory', 'JobHistory', 'PlacementHistory',
        'WorkSchedule',
    ];

    public function test_hr_schema_contains_exactly_the_two_authorized_tables(): void
    {
        $tables = DB::table('information_schema.tables')->where('table_schema', 'hr')->pluck('table_name')->all();
        sort($tables);

        $this->assertSame(['employment_relationships', 'persons'], $tables);
    }

    public function test_employment_relationship_has_no_organizational_unit_column(): void
    {
        $columns = DB::table('information_schema.columns')
            ->where('table_schema', 'hr')->where('table_name', 'employment_relationships')
            ->pluck('column_name')->all();

        $this->assertNotContains('organizational_unit_id', $columns, 'spec §17: no speculative organization column in S09');
    }

    public function test_person_has_no_name_or_demographic_columns(): void
    {
        $columns = DB::table('information_schema.columns')
            ->where('table_schema', 'hr')->where('table_name', 'persons')
            ->pluck('column_name')->all();

        foreach (['name', 'name_ar', 'name_en', 'gender', 'marital_status', 'date_of_birth'] as $forbidden) {
            $this->assertNotContains($forbidden, $columns, "spec §4: no {$forbidden} column in S09 v1");
        }
    }

    public function test_no_hr_route_exposes_an_out_of_scope_s10_plus_concept(): void
    {
        $hrRoutes = collect(Route::getRoutes())->filter(fn ($route) => str_starts_with($route->uri(), 'api/v1/hr'));

        $this->assertGreaterThan(0, $hrRoutes->count(), 'fixture assumption: S09 registered at least one hr/* route');

        foreach ($hrRoutes as $route) {
            foreach (self::FORBIDDEN_ROUTE_SEGMENTS as $forbidden) {
                $this->assertStringNotContainsString($forbidden, $route->uri(), "route {$route->uri()} must not expose the out-of-scope concept '{$forbidden}'");
            }
        }
    }

    public function test_the_human_resources_module_defines_no_out_of_scope_command(): void
    {
        $commandFiles = glob(app_path('Modules/HumanResources/Application/Commands/*.php'));
        $this->assertNotEmpty($commandFiles, 'fixture assumption: S09 registered at least one command');

        foreach ($commandFiles as $file) {
            $className = pathinfo($file, PATHINFO_FILENAME);
            foreach (self::FORBIDDEN_COMMAND_NAMES as $forbidden) {
                $this->assertStringNotContainsString($forbidden, $className, "command class {$className} must not be an out-of-scope S10+ concept");
            }
        }
    }

    public function test_no_generic_orchestration_or_crud_infrastructure_classes_exist(): void
    {
        $forbidden = [
            'app/Modules/HumanResources/Application/CommandBus.php',
            'app/Modules/HumanResources/Application/GenericCrudService.php',
            'app/Modules/HumanResources/Application/GenericRepository.php',
            'app/Modules/HumanResources/Application/UnitOfWork.php',
            'app/Modules/HumanResources/Infrastructure/Repository',
        ];

        foreach ($forbidden as $path) {
            $this->assertFileDoesNotExist(base_path($path), "forbidden-scope path exists: {$path}");
            $this->assertDirectoryDoesNotExist(base_path($path), "forbidden-scope path exists: {$path}");
        }
    }

    public function test_no_hr_route_exposes_a_hard_delete(): void
    {
        $routes = collect(Route::getRoutes())->filter(fn ($route) => str_starts_with($route->uri(), 'api/v1/hr'));

        $this->assertFalse(
            $routes->contains(fn ($route) => in_array('DELETE', $route->methods(), true)),
            'no S09 route may expose a hard delete (spec §19/§21)',
        );
    }

    public function test_every_hr_route_carries_a_permission_middleware(): void
    {
        $routes = collect(Route::getRoutes())->filter(fn ($route) => str_starts_with($route->uri(), 'api/v1/hr'));

        foreach ($routes as $route) {
            $hasPermissionMiddleware = collect($route->gatherMiddleware())
                ->contains(fn ($middleware) => str_starts_with($middleware, 'permission:'));

            $this->assertTrue($hasPermissionMiddleware, "route {$route->uri()} is missing a permission: middleware entry");
        }
    }

    public function test_no_reporting_or_frontend_content_was_added_for_s09(): void
    {
        $reportingTables = DB::table('information_schema.tables')->where('table_schema', 'reporting')->count();
        $this->assertSame(0, $reportingTables);

        $this->assertDirectoryDoesNotExist(base_path('../frontend/src/features/human-resources'));
    }

    public function test_the_to_delete_directory_is_untouched(): void
    {
        $this->assertDirectoryDoesNotExist(app_path('Modules/HumanResources/_to_delete'));
    }
}
