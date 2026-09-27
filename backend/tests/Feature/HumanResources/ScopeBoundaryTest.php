<?php

namespace Tests\Feature\HumanResources;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

/**
 * S09/S10/S11/S12 scope audit (docs/person-employment-foundation-specification.md §2/§17/§21/§24
 * P24, docs/employment-status-history-foundation-specification.md §3/§13/§18,
 * docs/organizational-placement-foundation-specification.md §6.1/§20/§25 P22, and
 * docs/full-secondment-foundation-specification.md §18/§26): the hr schema contains exactly the
 * five S09/S10/S11/S12-authorized tables and nothing else; no table carries a speculative column
 * (organizational-unit on the S09/S10 tables, name/demographic on persons, a client-versioned or
 * mutable-current-workplace column on placement periods, a decision-type or destination-scheme
 * column on secondment periods); no S13+ out-of-scope concept
 * (transfer/assignment/leave/qualification/professional-history/reporting/…) leaked in via any
 * route or command; no hard delete is exposed; _to_delete/ is untouched. 'placement' and
 * 'PlacementHistory' were removed from the forbidden lists in S11 (Organizational Placement became
 * the authorized S11 domain itself); 'secondment'/'Secondment' are removed here in S12 for the
 * identical reason — Full Secondment is now the authorized S12 domain itself (its own routes
 * legitimately contain 'full-secondment-periods'), not an out-of-scope concept to guard against.
 * Mirrors tests/Feature/Organization/ScopeBoundaryTest.php's and
 * tests/Feature/Reference/ScopeBoundaryTest.php's shape exactly.
 */
class ScopeBoundaryTest extends HumanResourcesTestCase
{
    private const FORBIDDEN_ROUTE_SEGMENTS = [
        'transfer', 'assignment', 'leave', 'qualification',
        'work-schedule', 'workschedule', 'renewal', 'professional-history',
        'job-history', 'export', 'report',
    ];

    private const FORBIDDEN_COMMAND_NAMES = [
        'Transfer', 'Assignment', 'Leave', 'ContractRenewal',
        'ProfessionalHistory', 'JobHistory',
        'WorkSchedule',
    ];

    public function test_hr_schema_contains_exactly_the_five_authorized_tables(): void
    {
        $tables = DB::table('information_schema.tables')->where('table_schema', 'hr')->pluck('table_name')->all();
        sort($tables);

        $this->assertSame(
            ['employment_relationships', 'employment_status_periods', 'full_secondment_periods', 'organizational_placement_periods', 'persons'],
            $tables,
        );
    }

    public function test_organizational_placement_period_has_no_speculative_columns(): void
    {
        $columns = DB::table('information_schema.columns')
            ->where('table_schema', 'hr')->where('table_name', 'organizational_placement_periods')
            ->pluck('column_name')->all();

        foreach (['version', 'current_workplace', 'is_current', 'decision_type_id', 'code', 'type'] as $forbidden) {
            $this->assertNotContains(
                $forbidden,
                $columns,
                "S11 spec §5.1/§14: no speculative {$forbidden} column — placement periods are append-only history, not a mutable current-workplace field",
            );
        }
    }

    public function test_full_secondment_period_has_no_speculative_columns(): void
    {
        $columns = DB::table('information_schema.columns')
            ->where('table_schema', 'hr')->where('table_name', 'full_secondment_periods')
            ->pluck('column_name')->all();

        foreach (['version', 'decision_type_id', 'code', 'type', 'is_partial', 'allocation_percentage'] as $forbidden) {
            $this->assertNotContains(
                $forbidden,
                $columns,
                "S12 spec §7.1/§19: no speculative {$forbidden} column — full secondment periods carry no decision-type reference (against the still-empty ref.decision_types catalog) and no Partial-Secondment discriminator",
            );
        }
    }

    public function test_employment_relationship_has_no_organizational_unit_column(): void
    {
        $columns = DB::table('information_schema.columns')
            ->where('table_schema', 'hr')->where('table_name', 'employment_relationships')
            ->pluck('column_name')->all();

        $this->assertNotContains('organizational_unit_id', $columns, 'spec §17: no speculative organization column in S09');
    }

    public function test_employment_status_period_has_no_organizational_unit_or_decision_type_column(): void
    {
        $columns = DB::table('information_schema.columns')
            ->where('table_schema', 'hr')->where('table_name', 'employment_status_periods')
            ->pluck('column_name')->all();

        $this->assertNotContains('organizational_unit_id', $columns, 'S10 spec §13: no organizational-scope target exists for status periods');
        $this->assertNotContains('decision_type_id', $columns, 'S10 spec §10: no speculative decision_type column against the still-empty ref.decision_types catalog');
        $this->assertNotContains('version', $columns, 'S10 spec §5: status periods are append-only, no independently client-versioned column');
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

    public function test_no_hr_route_exposes_an_out_of_scope_s12_plus_concept(): void
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
                $this->assertStringNotContainsString($forbidden, $className, "command class {$className} must not be an out-of-scope S12+ concept");
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

    public function test_no_reporting_or_frontend_content_was_added_for_s09_s10_s11_or_s12(): void
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
