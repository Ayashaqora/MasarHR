<?php

namespace Tests\Feature\HumanResources;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

/**
 * S09/S10/S11/S12/S14/S16/S20/S21/S22/S23 scope audit (docs/person-employment-foundation-specification.md
 * §2/§17/§21/§24 P24, docs/employment-status-history-foundation-specification.md §3/§13/§18,
 * docs/organizational-placement-foundation-specification.md §6.1/§20/§25 P22,
 * docs/full-secondment-foundation-specification.md §18/§26,
 * docs/transfer-foundation-specification.md §7/§26, and
 * docs/workplace-assignment-foundation-specification.md §S16.5/§S16.21, and
 * docs/employment-category-history-foundation-specification.md §S20.6/§S20.19, and
 * docs/employment-contract-foundation-specification.md §S21.6, and
 * docs/employment-job-title-history-foundation-specification.md §S22.6, and
 * docs/person-qualification-foundation-specification.md §S23.6, and
 * docs/employee-specialty-history-foundation-specification.md §S26.6, and
 * docs/work-schedule-foundation-specification.md §S29.6, and
 * docs/partial-secondment-foundation-specification.md §S30.6): the hr schema contains
 * exactly the fifteen S09/S10/S11/S12/S16/S20/S21/S22/S23/S26/S29/S30-authorized tables and nothing else — S14 adds none
 * (persistence-design Option B, ADR-S14-001 §16); no table carries a speculative column
 * (organizational-unit on the S09/S10 tables, name/demographic on persons, a client-versioned or
 * mutable-current-workplace column on placement periods, a decision-type or destination-scheme
 * column on secondment periods — S14's own decision_type_id is a transient command input and an
 * audit field only, never a schema column, confirmed below); no S17+ out-of-scope concept
 * (supervisory/leave/professional-history/reporting/…) leaked in via any route or
 * command; no hard delete is exposed; _to_delete/ is untouched. 'placement' and
 * 'PlacementHistory' were removed from the forbidden lists in S11 (Organizational Placement became
 * the authorized S11 domain itself); 'secondment'/'Secondment' were removed in S12 for the
 * identical reason; 'transfer'/'Transfer' were removed in S14; 'assignment'/'Assignment' are
 * removed here in S16 — Workplace Assignment Foundation is now the authorized S16 domain itself
 * (its own route/command legitimately contain 'assignment'/'Assignment'; supervisory assignment
 * remains strictly out of scope per ADR-S16-001 §17, but that concept is never named
 * 'assignment' anywhere in this codebase — it is named 'supervisory', which stays forbidden
 * below), not an out-of-scope concept to guard against. Mirrors
 * tests/Feature/Organization/ScopeBoundaryTest.php's and
 * tests/Feature/Reference/ScopeBoundaryTest.php's shape exactly.
 */
class ScopeBoundaryTest extends HumanResourcesTestCase
{
    private const FORBIDDEN_ROUTE_SEGMENTS = [
        // 'qualification' was removed in S23 — Person Qualification Foundation is now the
        // authorized S23 domain itself (docs/person-qualification-foundation-specification.md),
        // the same precedent as 'placement' (S11), 'secondment' (S12), 'transfer' (S14) and
        // 'assignment' (S16).
        // 'work-schedule'/'workschedule' (and 'WorkSchedule' below) were removed in S29 — Work
        // Schedule Foundation is now the authorized S29 domain itself
        // (docs/work-schedule-foundation-specification.md), the same precedent.
        'supervisory', 'leave',
        'renewal', 'professional-history',
        'job-history', 'export', 'report',
    ];

    private const FORBIDDEN_COMMAND_NAMES = [
        'Supervisory', 'Leave', 'ContractRenewal',
        'ProfessionalHistory', 'JobHistory',
    ];

    public function test_hr_schema_contains_exactly_the_fifteen_authorized_tables(): void
    {
        $tables = DB::table('information_schema.tables')->where('table_schema', 'hr')->pluck('table_name')->all();
        sort($tables);

        $this->assertSame(
            ['employment_category_periods', 'employment_contract_periods', 'employment_job_title_periods', 'employment_relationships', 'employment_specialty_periods', 'employment_status_periods', 'full_secondment_periods', 'organizational_placement_periods', 'partial_secondment_period_weekdays', 'partial_secondment_periods', 'person_qualifications', 'persons', 'work_schedule_period_weekdays', 'work_schedule_periods', 'workplace_assignment_periods'],
            $tables,
        );
    }

    /**
     * Regression guard for the S14 §16 persistence-design decision (ADR-S14-001, Option B): a
     * Transfer is represented entirely by the S11 placement row it writes, the S12 period it may
     * close, and its own audit entry — never a dedicated event/history table of its own. Mirrors
     * S13's own test_supervisory_statuses_table_was_deliberately_not_created guard.
     */
    public function test_no_dedicated_transfer_table_was_created(): void
    {
        foreach (['hr.transfers', 'hr.transfer_events', 'hr.transfer_history', 'hr.employee_transfers'] as $table) {
            [$schema, $name] = explode('.', $table);
            $exists = DB::table('information_schema.tables')
                ->where('table_schema', $schema)->where('table_name', $name)->exists();

            $this->assertFalse($exists, "S14 §16 chose persistence-design Option B: {$table} must not exist");
        }
    }

    /** Regression guard: TransferEmployee is the only S14 command, mirroring the §12 command model. */
    public function test_transfer_employee_is_the_only_s14_command(): void
    {
        $this->assertFileExists(
            base_path('app/Modules/HumanResources/Application/Commands/TransferEmployee.php'),
            'S14 must expose exactly one command, TransferEmployee (spec §12)',
        );

        foreach (['RecordTransferPeriod', 'CreateTransfer', 'TransferPerson'] as $notReal) {
            $this->assertFileDoesNotExist(
                base_path("app/Modules/HumanResources/Application/Commands/{$notReal}.php"),
                "spec §12 names exactly TransferEmployee; {$notReal} must not exist",
            );
        }
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
                "S11 spec §5.1/§14: no speculative {$forbidden} column — placement periods are append-only history, not a mutable current-workplace field. Still holds after S14: TransferEmployee writes this table entirely through S11's own RecordOrganizationalPlacementPeriod, adding no column (docs/transfer-foundation-specification.md §16/§7.1) — decision_type_id in particular is a TransferEmployee command input and an audit field only, never persisted here.",
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
                "S12 spec §7.1/§19: no speculative {$forbidden} column — full secondment periods carry no decision-type reference and no Partial-Secondment discriminator. Still holds after S14: even though ref.decision_types now has one authoritative row (TRANSFER, ADR-S14-002), TransferEmployee closes this table's rows entirely through S12's own EndFullSecondment, adding no column (docs/transfer-foundation-specification.md §16/§7.1).",
            );
        }
    }

    /** S16 spec §S16.5: mirrors test_full_secondment_period_has_no_speculative_columns exactly. */
    public function test_workplace_assignment_period_has_no_speculative_columns(): void
    {
        $columns = DB::table('information_schema.columns')
            ->where('table_schema', 'hr')->where('table_name', 'workplace_assignment_periods')
            ->pluck('column_name')->all();

        foreach (['version', 'decision_type_id', 'code', 'type', 'is_partial', 'allocation_percentage', 'supervisory_title_id', 'is_supervisory'] as $forbidden) {
            $this->assertNotContains(
                $forbidden,
                $columns,
                "S16 spec §S16.5: no speculative {$forbidden} column — workplace assignment periods carry no decision-type reference of their own (validated at command time, recorded only in the audit entry, mirroring Transfer's own precedent), no Partial-Secondment-style discriminator, and no supervisory-domain reference (ADR-S16-001 §17 keeps supervisory assignment strictly separate).",
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

    /**
     * S09 spec §4 kept hr.persons identity-only; S24 (docs/person-profile-foundation-specification.md
     * §S24.6) adds exactly five current profile attributes and nothing else — no name parts, no
     * English name, no age, no start-work date, no specialty/experience, no demographic history.
     */
    public function test_person_has_exactly_the_s09_identity_and_s24_profile_columns(): void
    {
        $columns = DB::table('information_schema.columns')
            ->where('table_schema', 'hr')->where('table_name', 'persons')
            ->pluck('column_name')->sort()->values()->all();

        $this->assertSame([
            'birth_date', 'birth_place', 'created_at', 'full_name_ar', 'gender_id', 'id', 'is_terminal',
            'marital_status_id', 'national_id', 'updated_at', 'version',
        ], $columns);
    }

    public function test_no_hr_route_exposes_an_out_of_scope_s14_plus_concept(): void
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
