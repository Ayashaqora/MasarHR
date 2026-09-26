<?php

use App\Modules\Platform\Infrastructure\Persistence\Postgres\TemporalConstraints;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * hr.employment_relationships — S09's Employment Relationship aggregate
 * (docs/person-employment-foundation-specification.md §6). One row per relationship interval;
 * rows are never hard-deleted (§8/§19) — the append-only history is itself the permanent
 * PERMANENT-employee-number reservation registry (§8), so no separate reservation table exists.
 *
 * `employee_number_scheme` is recorded at write time from the resolved ref.employment_types.code
 * (§8): a partial UNIQUE index cannot contain a subquery against another table, so the scheme is
 * denormalized onto this row specifically to make that index possible. Consistency between
 * `employee_number_scheme`/`employment_type_id` and between a CONTRACT row's `employee_number`
 * and the Person's `national_id` cannot be expressed as a CHECK (PostgreSQL CHECK constraints
 * cannot reference another table) — both are revalidated by CreateEmploymentRelationship inside
 * the AuditedCommandExecutor transaction (spec §8).
 *
 * Temporal integrity reuses TemporalConstraints exactly as every prior effective-dated table in
 * this repository does (spec §9) — no new mechanism is invented. Keyed on person_id alone: two
 * open-ended rows for the same person always overlap, which is what makes "at most one active
 * relationship" fall out of the same constraint as "no overlap" (spec §9).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr.employment_relationships', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('person_id');
            $table->uuid('employment_type_id');
            $table->string('employee_number', 64);
            $table->string('employee_number_scheme', 16);
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->string('end_knowledge_state', 16)->default('NOT_APPLICABLE');
            $table->boolean('ended_terminally')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestampTz('created_at');
            $table->timestampTz('updated_at');

            $table->foreign('person_id', 'employment_relationships_person_fk')
                ->references('id')->on('hr.persons')->restrictOnDelete();
            $table->foreign('employment_type_id', 'employment_relationships_employment_type_fk')
                ->references('id')->on('ref.employment_types')->restrictOnDelete();

            $table->index('person_id', 'employment_relationships_person_id_index');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE "hr"."employment_relationships"
                ADD CONSTRAINT "employment_relationships_scheme_check"
                CHECK ("employee_number_scheme" IN ('PERMANENT', 'CONTRACT'))
            SQL);

        // spec §12: end_knowledge_state must be internally consistent with effective_to and with
        // ended_terminally.
        DB::statement(<<<'SQL'
            ALTER TABLE "hr"."employment_relationships"
                ADD CONSTRAINT "employment_relationships_end_knowledge_state_check"
                CHECK (
                    "end_knowledge_state" IN ('KNOWN', 'UNKNOWN_LEGACY', 'NOT_APPLICABLE')
                    AND (
                        ("end_knowledge_state" = 'KNOWN' AND "effective_to" IS NOT NULL)
                        OR ("end_knowledge_state" IN ('NOT_APPLICABLE', 'UNKNOWN_LEGACY') AND "effective_to" IS NULL)
                    )
                    AND ("end_knowledge_state" = 'KNOWN' OR "ended_terminally" IS NULL)
                )
            SQL);

        DB::statement(TemporalConstraints::validPeriodCheckSql(
            'hr.employment_relationships',
            'employment_relationships_period_check',
        ));

        DB::statement(TemporalConstraints::noOverlapConstraintSql(
            'hr.employment_relationships',
            'employment_relationships_no_overlap',
            ['person_id'],
        ));

        // spec §8/§10: a PERMANENT employee_number may never be reused, by any Person, for all
        // time. Rows are never hard-deleted, so this partial unique index over the whole,
        // append-only history is itself the durable reservation registry — no separate table.
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX "employment_relationships_permanent_number_unique"
                ON "hr"."employment_relationships" ("employee_number")
                WHERE "employee_number_scheme" = 'PERMANENT'
            SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE "hr"."employment_relationships"
                ADD CONSTRAINT "employment_relationships_version_check"
                CHECK ("version" >= 1)
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('hr.employment_relationships');
    }
};
