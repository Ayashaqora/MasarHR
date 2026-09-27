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
 *
 * S13 (docs/reference-catalog-administration-foundation-specification.md) adds no new ref.* table
 * — it only adds administration (command/controller/route) for eight of S05's ten
 * structure-only families, plus one seed migration into an already-existing table — so the
 * twenty-two-table count below is unchanged. The command/controller boundary the last two tests in
 * this file assert has moved accordingly: see their docblocks.
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
        // 'hr' is likewise excluded now that S09
        // (docs/person-employment-foundation-specification.md) has populated it — see
        // tests/Feature/HumanResources/ScopeBoundaryTest.php.
        foreach (['reporting'] as $schema) {
            $count = DB::table('information_schema.tables')->where('table_schema', $schema)->count();
            $this->assertSame(0, $count, "schema {$schema} must stay empty until its owning stage runs");
        }
    }

    public function test_no_hr_person_employee_or_organization_tables_leaked_in_via_s05(): void
    {
        // 'hr' is deliberately excluded here now that S09
        // (docs/person-employment-foundation-specification.md) is its own authorized owning
        // stage and legitimately populates it with hr.persons/hr.employment_relationships — both
        // of which would otherwise trip this exact forbidden-word list. See
        // tests/Feature/HumanResources/ScopeBoundaryTest.php for S09's own precise boundary check.
        $forbidden = ['employee', 'employees', 'person', 'persons', 'national_id',
            'organization_unit', 'organization_units', 'contract_record', 'leave_request',
            'secondment', 'transfer', 'reappointment', ];

        $tables = DB::table('information_schema.tables')
            ->whereIn('table_schema', ['ref', 'org', 'reporting', 'public'])
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

    /**
     * Of S05 §5.3's original ten "structure-only, values deferred" families, S13
     * (docs/reference-catalog-administration-foundation-specification.md §6/§7) deliberately built
     * full administration (command + controller) for eight of them — every one the S13 authorization
     * named as a target catalog — and left exactly two untouched: EmploymentType and Specialty are
     * not named by the S13 authorization (spec §7) and must remain exactly as S05 left them. This
     * updates the pre-S13 assertion (which required all ten to have neither) rather than deleting
     * it, so the boundary this test protects — "no catalog gets administration Architecture
     * Authority never authorized" — still holds, now drawn in the place S13 actually put it.
     */
    public function test_only_the_two_out_of_scope_structure_only_families_still_expose_no_command_or_route(): void
    {
        $stillStructureOnly = ['EmploymentType', 'Specialty'];

        foreach ($stillStructureOnly as $family) {
            $this->assertFileDoesNotExist(
                base_path("app/Modules/Reference/Application/Commands/Create{$family}.php"),
                "{$family} is out of S13 scope (spec §7) — it must still expose no command",
            );
            $this->assertFileDoesNotExist(
                base_path("app/Modules/Reference/Presentation/Http/Controllers/{$family}Controller.php"),
                "{$family} is out of S13 scope (spec §7) — it must still expose no controller",
            );
        }
    }

    public function test_the_eight_s13_in_scope_families_now_expose_full_administration(): void
    {
        $s13Administered = ['ContractType', 'EmploymentCategory', 'QualificationType',
            'AcademicDegree', 'JobTitle', 'SupervisoryTitle', 'LeaveType', 'LeaveStatus', ];

        foreach ($s13Administered as $family) {
            foreach (['Create', 'Activate', 'Deactivate'] as $verb) {
                $this->assertFileExists(
                    base_path("app/Modules/Reference/Application/Commands/{$verb}{$family}.php"),
                    "{$family} is an S13 in-scope catalog (spec §6) — it must expose {$verb}{$family}",
                );
            }
            $this->assertFileExists(
                base_path("app/Modules/Reference/Application/Commands/Update{$family}Metadata.php"),
                "{$family} is an S13 in-scope catalog (spec §6) — it must expose Update{$family}Metadata",
            );
            $this->assertFileExists(
                base_path("app/Modules/Reference/Presentation/Http/Controllers/{$family}Controller.php"),
                "{$family} is an S13 in-scope catalog (spec §6) — it must expose a controller",
            );
        }
    }
}
