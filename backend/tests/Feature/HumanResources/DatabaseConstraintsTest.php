<?php

namespace Tests\Feature\HumanResources;

use App\Modules\Platform\Infrastructure\Persistence\Postgres\PostgresErrorClassifier as Errors;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;

/** Direct PostgreSQL constraint coverage for hr.persons/hr.employment_relationships (spec §21-§23). */
class DatabaseConstraintsTest extends HumanResourcesTestCase
{
    public function test_the_active_connection_is_postgresql(): void
    {
        $this->assertSame('pgsql', DB::connection()->getDriverName());
    }

    public function test_hr_tables_are_owned_by_the_hr_schema(): void
    {
        $tables = DB::table('information_schema.tables')
            ->where('table_schema', 'hr')
            ->pluck('table_name')->sort()->values()->all();

        $this->assertSame(['employment_relationships', 'persons'], $tables);
    }

    public function test_an_employment_relationship_foreign_key_to_a_nonexistent_person_is_rejected(): void
    {
        $employmentType = $this->employmentType('permanent');

        $error = $this->tryAndCatch(function () use ($employmentType): void {
            DB::table('hr.employment_relationships')->insert([
                'id' => (string) Str::uuid7(),
                'person_id' => (string) Str::uuid7(),
                'employment_type_id' => $employmentType->id,
                'employee_number' => 'X',
                'employee_number_scheme' => 'PERMANENT',
                'effective_from' => '2026-01-01',
                'end_knowledge_state' => 'NOT_APPLICABLE',
                'version' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $this->assertTrue(Errors::isForeignKeyViolation($error));
    }

    public function test_an_employment_relationship_foreign_key_to_a_nonexistent_employment_type_is_rejected(): void
    {
        $person = $this->createPersonRecord();

        $error = $this->tryAndCatch(function () use ($person): void {
            DB::table('hr.employment_relationships')->insert([
                'id' => (string) Str::uuid7(),
                'person_id' => $person->id,
                'employment_type_id' => (string) Str::uuid7(),
                'employee_number' => 'X',
                'employee_number_scheme' => 'PERMANENT',
                'effective_from' => '2026-01-01',
                'end_knowledge_state' => 'NOT_APPLICABLE',
                'version' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $this->assertTrue(Errors::isForeignKeyViolation($error));
    }

    public function test_an_unsupported_employee_number_scheme_is_rejected_by_the_check_constraint(): void
    {
        $person = $this->createPersonRecord();
        $employmentType = $this->employmentType('permanent');

        $error = $this->tryAndCatch(function () use ($person, $employmentType): void {
            DB::table('hr.employment_relationships')->insert([
                'id' => (string) Str::uuid7(),
                'person_id' => $person->id,
                'employment_type_id' => $employmentType->id,
                'employee_number' => 'X',
                'employee_number_scheme' => 'TEMPORARY',
                'effective_from' => '2026-01-01',
                'end_knowledge_state' => 'NOT_APPLICABLE',
                'version' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $this->assertTrue(Errors::isCheckViolation($error));
    }

    #[DataProvider('inconsistentEndKnowledgeStateRows')]
    public function test_an_inconsistent_end_knowledge_state_is_rejected_by_the_check_constraint(string $state, ?string $effectiveTo): void
    {
        $person = $this->createPersonRecord();
        $employmentType = $this->employmentType('permanent');

        $error = $this->tryAndCatch(function () use ($person, $employmentType, $state, $effectiveTo): void {
            DB::table('hr.employment_relationships')->insert([
                'id' => (string) Str::uuid7(),
                'person_id' => $person->id,
                'employment_type_id' => $employmentType->id,
                'employee_number' => 'X',
                'employee_number_scheme' => 'PERMANENT',
                'effective_from' => '2026-01-01',
                'effective_to' => $effectiveTo,
                'end_knowledge_state' => $state,
                'version' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $this->assertTrue(Errors::isCheckViolation($error));
    }

    /** @return array<string, array{string, ?string}> */
    public static function inconsistentEndKnowledgeStateRows(): array
    {
        return [
            'KNOWN without an effective_to' => ['KNOWN', null],
            'NOT_APPLICABLE with an effective_to' => ['NOT_APPLICABLE', '2026-02-01'],
            'UNKNOWN_LEGACY with an effective_to' => ['UNKNOWN_LEGACY', '2026-02-01'],
        ];
    }

    public function test_ended_terminally_must_be_null_unless_end_knowledge_state_is_known(): void
    {
        $person = $this->createPersonRecord();
        $employmentType = $this->employmentType('permanent');

        $error = $this->tryAndCatch(function () use ($person, $employmentType): void {
            DB::table('hr.employment_relationships')->insert([
                'id' => (string) Str::uuid7(),
                'person_id' => $person->id,
                'employment_type_id' => $employmentType->id,
                'employee_number' => 'X',
                'employee_number_scheme' => 'PERMANENT',
                'effective_from' => '2026-01-01',
                'end_knowledge_state' => 'NOT_APPLICABLE',
                'ended_terminally' => false,
                'version' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $this->assertTrue(Errors::isCheckViolation($error));
    }

    public function test_a_reversed_or_empty_period_is_rejected_by_the_check_constraint(): void
    {
        $person = $this->createPersonRecord();
        $employmentType = $this->employmentType('permanent');

        $error = $this->tryAndCatch(function () use ($person, $employmentType): void {
            DB::table('hr.employment_relationships')->insert([
                'id' => (string) Str::uuid7(),
                'person_id' => $person->id,
                'employment_type_id' => $employmentType->id,
                'employee_number' => 'X',
                'employee_number_scheme' => 'PERMANENT',
                'effective_from' => '2026-02-01',
                'effective_to' => '2026-01-01',
                'end_knowledge_state' => 'KNOWN',
                'version' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $this->assertTrue(Errors::isCheckViolation($error));
    }

    public function test_an_unsupported_person_version_is_rejected_by_the_check_constraint(): void
    {
        $error = $this->tryAndCatch(function (): void {
            DB::table('hr.persons')->insert([
                'id' => (string) Str::uuid7(),
                'national_id' => (string) random_int(1_000_000_000, 9_999_999_999),
                'is_terminal' => false,
                'version' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $this->assertTrue(Errors::isCheckViolation($error));
    }

    private function tryAndCatch(callable $work): QueryException
    {
        try {
            $work();
        } catch (QueryException $e) {
            return $e;
        }

        $this->fail('Expected a QueryException.');
    }
}
