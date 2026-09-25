<?php

namespace Tests\Feature\Security;

use App\Modules\Platform\Infrastructure\Persistence\Postgres\PostgresErrorClassifier as Errors;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** §30 DATABASE / §28 of the S03 authorization. */
class DatabaseConstraintsTest extends SecurityTestCase
{
    public function test_the_active_connection_is_postgresql(): void
    {
        $this->assertSame('pgsql', DB::connection()->getDriverName());
    }

    public function test_security_tables_are_owned_by_the_security_schema(): void
    {
        $tables = DB::table('information_schema.tables')
            ->where('table_schema', 'security')
            ->pluck('table_name')->sort()->values()->all();

        $this->assertSame(
            ['credentials', 'permissions', 'principal_roles', 'principals', 'role_permissions', 'roles'],
            $tables,
        );
    }

    public function test_no_default_laravel_users_table_was_accidentally_activated(): void
    {
        $exists = (bool) DB::selectOne(
            "select exists (select 1 from information_schema.tables where table_name = 'users') as e"
        )->e;

        $this->assertFalse($exists, 'the deferred framework users migration must never be activated by S03');
    }

    public function test_a_credential_foreign_key_to_a_nonexistent_principal_is_rejected(): void
    {
        $error = $this->tryAndCatch(function (): void {
            DB::table('security.credentials')->insert([
                'id' => (string) Str::uuid7(),
                'principal_id' => (string) Str::uuid7(),
                'credential_type' => 'PASSWORD',
                'password_hash' => 'irrelevant',
                'password_changed_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $this->assertTrue(Errors::isForeignKeyViolation($error));
    }

    public function test_a_second_password_credential_for_the_same_principal_is_rejected_by_the_unique_constraint(): void
    {
        $principal = $this->createPrincipal();

        $error = $this->tryAndCatch(function () use ($principal): void {
            DB::table('security.credentials')->insert([
                'id' => (string) Str::uuid7(),
                'principal_id' => $principal->getKey(),
                'credential_type' => 'PASSWORD',
                'password_hash' => 'irrelevant',
                'password_changed_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $this->assertTrue(Errors::isUniqueViolation($error));
    }

    public function test_an_unsupported_credential_type_is_rejected_by_the_check_constraint(): void
    {
        $principal = $this->createPrincipal();

        $error = $this->tryAndCatch(function () use ($principal): void {
            DB::table('security.credentials')->insert([
                'id' => (string) Str::uuid7(),
                'principal_id' => $principal->getKey(),
                'credential_type' => 'BIOMETRIC',
                'password_hash' => 'irrelevant',
                'password_changed_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $this->assertTrue(Errors::isCheckViolation($error));
    }

    public function test_an_unsupported_principal_status_is_rejected_by_the_check_constraint(): void
    {
        $error = $this->tryAndCatch(function (): void {
            DB::table('security.principals')->insert([
                'id' => (string) Str::uuid7(),
                'username' => 'checkfail',
                'username_normalized' => 'checkfail',
                'display_name' => 'x',
                'status' => 'LOCKED',
                'version' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $this->assertTrue(Errors::isCheckViolation($error));
    }

    public function test_a_role_assignment_foreign_key_to_a_nonexistent_role_is_rejected(): void
    {
        $principal = $this->createPrincipal();

        $error = $this->tryAndCatch(function () use ($principal): void {
            DB::table('security.principal_roles')->insert([
                'id' => (string) Str::uuid7(),
                'principal_id' => $principal->getKey(),
                'role_id' => (string) Str::uuid7(),
                'assigned_at' => now(),
                'assigned_by' => null,
            ]);
        });

        $this->assertTrue(Errors::isForeignKeyViolation($error));
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
