<?php

namespace Tests\Feature\Reference;

use Illuminate\Support\Facades\DB;

/**
 * S05/S06 scope audit (docs/reference-data-foundation-specification.md §26,
 * docs/versioned-behavior-reporting-references-specification.md §12/§34): the ref schema contains
 * exactly the 16 originally S05-authorized reference tables, plus the CORRECTIVE-01
 * marital_status_aliases lookup table (§22a), plus the 5 S06 tables (2 rich catalogs + 3 temporal
 * mappings, spec §12) — 22 total — and nothing else; no
 * Person/Employee/Employment/Organization/transaction table leaked in via S05 or S06; hr/reporting
 * stay empty (org stays empty only through S06 — S07 legitimately populates it, see the note on
 * test_hr_and_reporting_schemas_remain_empty_after_s05() below); no generic
 * Command-Bus/CRUD-service/repository/Unit-of-Work infrastructure was introduced.
 */
class ScopeBoundaryTest extends ReferenceTestCase
{
    public function test_ref_schema_contains_exactly_the_twenty_two_authorized_tables(): void
    {
        $expected = [
            'genders', 'marital_statuses', 'marital_status_aliases', 'decision_types', 'employment_status_categories',
            'employment_status_details', 'employment_status_detail_behaviors',
            'employment_types', 'contract_types', 'employment_categories', 'qualification_types',
            'academic_degrees', 'job_titles', 'specialties', 'supervisory_titles',
            'leave_types', 'leave_statuses',
            // S06 additions (spec §12):
            'monthly_cadre_categories', 'contract_based_population_categories',
            'specialty_cadre_category_mappings', 'job_title_administrator_classifications',
            'contract_type_population_mappings',
        ];

        $tables = DB::table('information_schema.tables')->where('table_schema', 'ref')->pluck('table_name')->all();

        sort($expected);
        sort($tables);
        $this->assertSame($expected, $tables);
    }

    public function test_hr_and_reporting_schemas_remain_empty_after_s05(): void
    {
        // 'org' was empty through S05/S06 and is deliberately excluded here now that S07
        // (docs/organization-hierarchy-foundation-specification.md) has populated it with its own
        // table, org.organizational_units — that stage's own ScopeBoundaryTest
        // (tests/Feature/Organization/ScopeBoundaryTest.php) asserts its exact, narrow contents.
        foreach (['hr', 'reporting'] as $schema) {
            $count = DB::table('information_schema.tables')->where('table_schema', $schema)->count();
            $this->assertSame(0, $count, "schema {$schema} must stay empty until its owning stage runs");
        }
    }

    public function test_no_hr_person_employee_or_organization_tables_leaked_in_via_s05(): void
    {
        $forbidden = ['employee', 'employees', 'person', 'persons', 'national_id',
            'organization_unit', 'organization_units', 'contract_record', 'leave_request',
            'secondment', 'transfer', 'reappointment', ];

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
            'app/Modules/Reference/Application/CommandBus.php',
            'app/Modules/Reference/Application/GenericCrudService.php',
            'app/Modules/Reference/Application/GenericRepository.php',
            'app/Modules/Reference/Application/UnitOfWork.php',
            'app/Modules/Reference/Infrastructure/Repository',
        ];

        foreach ($forbidden as $path) {
            $this->assertFileDoesNotExist(base_path($path), "forbidden-scope path exists: {$path}");
            $this->assertDirectoryDoesNotExist(base_path($path), "forbidden-scope path exists: {$path}");
        }
    }

    public function test_no_reference_value_route_exposes_a_hard_delete(): void
    {
        $routes = collect(app('router')->getRoutes())
            ->filter(fn ($route) => str_starts_with($route->uri(), 'api/v1/reference/'));

        $this->assertFalse(
            $routes->contains(fn ($route) => in_array('DELETE', $route->methods(), true)),
            'no S05 reference route may expose a hard delete (§9)',
        );
    }

    public function test_the_ten_structure_only_families_expose_no_command_or_route(): void
    {
        $structureOnly = ['EmploymentType', 'ContractType', 'EmploymentCategory', 'QualificationType',
            'AcademicDegree', 'JobTitle', 'Specialty', 'SupervisoryTitle', 'LeaveType', 'LeaveStatus', ];

        foreach ($structureOnly as $family) {
            $this->assertFileDoesNotExist(
                base_path("app/Modules/Reference/Application/Commands/Create{$family}.php"),
                "{$family} is DEFINED STRUCTURE / VALUES DEFERRED (§5.3) — it must expose no command",
            );
            $this->assertFileDoesNotExist(
                base_path("app/Modules/Reference/Presentation/Http/Controllers/{$family}Controller.php"),
                "{$family} is DEFINED STRUCTURE / VALUES DEFERRED (§5.3) — it must expose no controller",
            );
        }
    }
}
