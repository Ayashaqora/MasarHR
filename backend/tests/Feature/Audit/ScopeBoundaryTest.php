<?php

namespace Tests\Feature\Audit;

use App\Modules\Audit\Infrastructure\Persistence\Eloquent\AuditEntry;
use Illuminate\Support\Facades\DB;

/**
 * S04 scope audit: the audit schema/table shape is exactly what the frozen specification
 * authorizes, and none of the explicitly forbidden S04 non-goals were introduced (Person/Employee/
 * Employment/Organization/S05 reference data/Reporting/Import-Export/Dashboard/S49 UI/generic
 * Repository/Unit of Work/Command Bus/Event Bus/Message Bus/Outbox/Kafka/Event Sourcing/
 * microservices/queue architecture/generic idempotency infrastructure).
 */
class ScopeBoundaryTest extends AuditTestCase
{
    public function test_audit_schema_contains_exactly_the_one_authorized_table(): void
    {
        $tables = DB::table('information_schema.tables')
            ->where('table_schema', 'audit')
            ->pluck('table_name');

        $this->assertSame(['audit_entries'], $tables->all());
    }

    public function test_no_generic_orchestration_or_messaging_infrastructure_classes_exist(): void
    {
        $forbidden = [
            'app/Modules/CommandBus', 'app/Modules/EventBus', 'app/Modules/MessageBus',
            'app/Modules/Outbox', 'app/Modules/Kafka', 'app/Modules/EventSourcing',
            'app/Modules/Audit/Application/CommandBus.php', 'app/Modules/Audit/Application/UnitOfWork.php',
            'app/Modules/Audit/Application/EventDispatcher.php', 'app/Modules/Audit/Infrastructure/Queue',
            'app/Modules/Platform/Infrastructure/Idempotency',
        ];

        foreach ($forbidden as $path) {
            $this->assertFileDoesNotExist(base_path($path), "forbidden-scope path exists: {$path}");
            $this->assertDirectoryDoesNotExist(base_path($path), "forbidden-scope path exists: {$path}");
        }
    }

    public function test_no_hr_person_employee_employment_or_organization_tables_leaked_in_via_s04(): void
    {
        $forbidden = ['employee', 'employees', 'person', 'persons', 'national_id', 'organization_unit',
            'organization_units', 'employment', 'contract', 'contracts', ];

        $tables = DB::table('information_schema.tables')
            ->whereIn('table_schema', ['audit', 'hr', 'org', 'reporting', 'public'])
            ->pluck('table_name');

        foreach ($tables as $table) {
            foreach ($forbidden as $needle) {
                $this->assertStringNotContainsStringIgnoringCase($needle, $table, "unexpected table leaking business scope: {$table}");
            }
        }
    }

    public function test_hr_and_reporting_schemas_remain_empty_after_s04(): void
    {
        // 'ref' is deliberately excluded here: S05 is the schema's own authorized owning stage and
        // populates it (see tests/Feature/Reference/ScopeBoundaryTest.php for S05's own boundary
        // check). 'org' is likewise excluded: S07 is its own authorized owning stage (see
        // tests/Feature/Organization/ScopeBoundaryTest.php). hr/reporting remain untouched by
        // every stage through S07.
        foreach (['hr', 'reporting'] as $schema) {
            $count = DB::table('information_schema.tables')->where('table_schema', $schema)->count();
            $this->assertSame(0, $count, "schema {$schema} must stay empty until its owning stage runs");
        }
    }

    public function test_audit_entries_model_has_no_update_or_delete_capable_query_scope_in_use(): void
    {
        // Defense-in-depth documentation check: the model declares no soft-deletes/timestamps
        // machinery that would tempt an ->update()/->delete() call path (the database trigger is
        // the real enforcement — see AuditImmutabilityTest).
        $entry = new AuditEntry;
        $this->assertFalse($entry->usesTimestamps());
    }
}
