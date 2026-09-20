<?php

namespace Tests\Feature\Database;

use Illuminate\Support\Facades\DB;
use Tests\PostgresIntegrationTestCase;

class PostgresFoundationTest extends PostgresIntegrationTestCase
{
    /** @var list<string> the eight architectural namespaces */
    private const SCHEMAS = ['hr', 'ref', 'org', 'reporting', 'security', 'audit', 'automation', 'migration'];

    public function test_postgresql_is_the_active_test_driver_and_sqlite_is_not_in_play(): void
    {
        $this->assertSame('pgsql', config('database.default'));
        $this->assertSame('pgsql', DB::connection()->getDriverName());
        $this->assertStringContainsString('PostgreSQL', (string) $this->scalar('select version()'));

        foreach (array_keys(config('database.connections')) as $name) {
            if ($name !== 'sqlite') {
                continue;
            }
            // Laravel's stock sqlite definition may exist in config, but it must never be selected.
            $this->assertNotSame($name, config('database.default'));
        }
    }

    public function test_server_is_a_supported_postgresql_release(): void
    {
        $this->assertGreaterThanOrEqual(160000, (int) $this->scalar("select current_setting('server_version_num')::int"));
    }

    public function test_tests_run_on_the_isolated_test_database_with_a_least_privilege_role(): void
    {
        $this->assertMatchesRegularExpression('/^masarhr_test(_[0-9]+)?$/', (string) $this->scalar('select current_database()'));

        $this->assertFalse((bool) $this->scalar('select rolsuper from pg_roles where rolname = current_user'), 'application role must not be a superuser');
        $this->assertFalse((bool) $this->scalar('select rolcreaterole from pg_roles where rolname = current_user'));
        $this->assertFalse((bool) $this->scalar('select rolreplication from pg_roles where rolname = current_user'));
    }

    public function test_all_eight_schema_namespaces_exist(): void
    {
        $found = collect($this->pg()->select(
            'select nspname from pg_namespace where nspname in ('.implode(',', array_fill(0, count(self::SCHEMAS), '?')).')',
            self::SCHEMAS,
        ))->pluck('nspname')->sort()->values()->all();

        $expected = self::SCHEMAS;
        sort($expected);

        $this->assertSame($expected, $found);
    }

    public function test_namespaces_are_empty_because_s02_owns_no_business_tables(): void
    {
        $objects = $this->pg()->select(
            'select n.nspname, c.relname from pg_class c join pg_namespace n on n.oid = c.relnamespace '
            .'where n.nspname in ('.implode(',', array_fill(0, count(self::SCHEMAS), '?')).')',
            self::SCHEMAS,
        );

        $this->assertSame([], $objects, 'S02 must not create any object inside the HR namespaces');
    }

    public function test_each_namespace_documents_its_purpose(): void
    {
        foreach (self::SCHEMAS as $schema) {
            $comment = (string) $this->scalar('select obj_description(?::regnamespace, ?)', [$schema, 'pg_namespace']);
            $this->assertStringStartsWith('MasarHR namespace:', $comment, $schema);
        }
    }

    public function test_btree_gist_is_installed_and_no_unneeded_extensions_were_added(): void
    {
        $extensions = collect($this->pg()->select('select extname from pg_extension'))->pluck('extname')->all();

        $this->assertContains('btree_gist', $extensions);
        $this->assertNotContains('uuid-ossp', $extensions, 'uuid-ossp is not required (gen_random_uuid() is core)');
        $this->assertNotContains('pgcrypto', $extensions, 'pgcrypto is not required (gen_random_uuid() is core)');
    }

    public function test_every_session_runs_in_utc(): void
    {
        $this->assertSame('UTC', $this->scalar('show timezone'));
    }

    public function test_the_default_transaction_isolation_is_read_committed(): void
    {
        $this->assertSame('read committed', $this->scalar('show transaction_isolation'));
    }

    public function test_only_the_s02_migrations_have_run_and_no_authentication_schema_was_created(): void
    {
        $ran = collect($this->pg()->select('select migration from migrations order by migration'))->pluck('migration')->all();

        $this->assertSame([
            '2026_09_20_000001_enable_postgresql_btree_gist_extension',
            '2026_09_20_000002_create_database_schema_namespaces',
        ], $ran);

        foreach (['users', 'password_reset_tokens', 'sessions', 'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs'] as $table) {
            $this->assertNull($this->scalar('select to_regclass(?)', ["public.{$table}"]), "public.{$table} must not exist in S02");
        }
    }
}
