<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
 * S02 — Database & Persistence Foundation.
 *
 * Creates exactly the eight PostgreSQL schemas that are the architectural namespaces of MasarHR.
 * They are empty by design: HR business tables belong to later, separately authorized stages.
 *
 * The list is intentionally hard-coded here (a released migration must never change behaviour
 * when application code changes).
 *
 * Rollback never uses CASCADE. If a schema still contains objects, PostgreSQL refuses to drop
 * it, this migration fails explicitly, and (because PostgreSQL DDL is transactional) the whole
 * rollback is undone. Nothing is destroyed silently.
 */
return new class extends Migration
{
    /** @var array<string, string> schema => purpose */
    private const SCHEMAS = [
        'hr' => 'MasarHR namespace: core HR domain data',
        'ref' => 'MasarHR namespace: reference data',
        'org' => 'MasarHR namespace: organizational structure',
        'reporting' => 'MasarHR namespace: reporting',
        'security' => 'MasarHR namespace: security and access control',
        'audit' => 'MasarHR namespace: audit trail',
        'automation' => 'MasarHR namespace: automation and background processing',
        'migration' => 'MasarHR namespace: legacy data migration',
    ];

    public function up(): void
    {
        $this->assertPostgreSql();

        foreach (self::SCHEMAS as $schema => $purpose) {
            DB::statement(sprintf('CREATE SCHEMA IF NOT EXISTS "%s"', $schema));
            DB::statement(sprintf('COMMENT ON SCHEMA "%s" IS %s', $schema, DB::getPdo()->quote($purpose)));
        }
    }

    public function down(): void
    {
        $this->assertPostgreSql();

        foreach (array_reverse(array_keys(self::SCHEMAS)) as $schema) {
            try {
                DB::statement(sprintf('DROP SCHEMA IF EXISTS "%s" RESTRICT', $schema));
            } catch (QueryException $exception) {
                throw new RuntimeException(
                    sprintf(
                        'Refusing to roll back: schema "%s" still contains objects. '
                        .'Migrations never drop schemas destructively; remove its contents through '
                        .'a reviewed corrective migration first.',
                        $schema,
                    ),
                    previous: $exception,
                );
            }
        }
    }

    private function assertPostgreSql(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new RuntimeException('MasarHR migrations require PostgreSQL; refusing to run on another driver.');
        }
    }
};
