<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * org.organizational_units — S07's sole table
 * (docs/organization-hierarchy-foundation-specification.md §21): the authoritative
 * organizational-unit hierarchy, an adjacency list via a nullable self-referencing `parent_id`
 * (spec §12). Deliberately no `type`/`level`/`code` column (spec §11) and no temporal/effective-
 * dated model (spec §16) — current-state fields only. Zero rows are seeded here or by any other
 * migration (spec §24): every unit is created later, by an authorized administrator, through the
 * API. `org` was created as an empty namespace by S02's
 * 2026_09_20_000002_create_database_schema_namespaces migration; this is the first migration to
 * populate it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('org.organizational_units', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('parent_id')->nullable();
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('version')->default(1);
            $table->timestampTz('created_at');
            $table->timestampTz('updated_at');

            // Every child/ancestor/descendant query filters on parent_id directly (spec §22).
            $table->index('parent_id', 'organizational_units_parent_id_index');
        });

        // The self-referencing FK is added via a separate statement, after the table (and its
        // primary key) already exist, rather than via a fluent $table->foreign() call inside
        // Schema::create() above: Laravel's Postgres grammar orders that fluent call's ALTER TABLE
        // ADD CONSTRAINT ... FOREIGN KEY before its own ALTER TABLE ADD PRIMARY KEY for this same
        // table, which fails self-referencing FKs with "there is no unique constraint matching
        // given keys" — verified against this exact migration during S07 implementation. No
        // dangling parent references (spec §20) — enforced by the database, not just route-model
        // binding.
        DB::statement(<<<'SQL'
            ALTER TABLE "org"."organizational_units"
                ADD CONSTRAINT "organizational_units_parent_id_foreign"
                FOREIGN KEY ("parent_id") REFERENCES "org"."organizational_units" ("id")
            SQL);

        // Self-parenting is physically impossible regardless of any application bug or race
        // (spec §13, layer 1). Indirect cycles are prevented transactionally by
        // MoveOrganizationalUnit (spec §13, layer 2), not by the database.
        DB::statement(<<<'SQL'
            ALTER TABLE "org"."organizational_units"
                ADD CONSTRAINT "organizational_units_not_self_parent_check"
                CHECK ("parent_id" IS NULL OR "parent_id" <> "id")
            SQL);

        // Mirrors every S05/S06 rich reference-value family's version check.
        DB::statement(<<<'SQL'
            ALTER TABLE "org"."organizational_units"
                ADD CONSTRAINT "organizational_units_version_check"
                CHECK ("version" >= 1)
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('org.organizational_units');
    }
};
