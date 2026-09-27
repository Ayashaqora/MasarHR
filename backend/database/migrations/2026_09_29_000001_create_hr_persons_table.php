<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * hr.persons — S09's Person aggregate (docs/person-employment-foundation-specification.md §4):
 * identity only. `national_id` is the permanent business reference (§5), not the technical PK.
 * `is_terminal` is set exactly once, by EndEmploymentRelationship (§10), and never unset — no
 * command in this module ever writes `is_terminal = false` over an existing `true` value.
 *
 * `hr` was created as an empty namespace by S02's 2026_09_20_000002_create_database_schema_
 * namespaces migration specifically for this domain (docs/database-persistence-foundation.md §2);
 * this is the first migration to populate it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr.persons', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('national_id', 64);
            $table->boolean('is_terminal')->default(false);
            $table->unsignedInteger('version')->default(1);
            $table->timestampTz('created_at');
            $table->timestampTz('updated_at');

            $table->unique('national_id', 'persons_national_id_unique');
        });

        // Mirrors every S05/S07/S08 rich/versioned table's version check.
        DB::statement(<<<'SQL'
            ALTER TABLE "hr"."persons"
                ADD CONSTRAINT "persons_version_check"
                CHECK ("version" >= 1)
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('hr.persons');
    }
};
