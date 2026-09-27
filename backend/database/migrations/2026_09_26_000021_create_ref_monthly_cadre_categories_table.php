<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ref.monthly_cadre_categories — the twelve Monthly Human Cadre report categories (Report 1,
 * S06-AUTH-001 §4.3): doctors, nursing, administration, laboratory, pharmacy, radiology, physical
 * therapy, anesthesia, other health professions, engineering, services, crafts. A first-class
 * reference catalog with stable UUID identity (S06 spec §12.1/§13) — reporting logic must join on
 * `code`/`id`, never match on `name_ar`/`name_en`. Same simple-reference-value shape as
 * ref.decision_types (full CRUD lifecycle: Create/UpdateMetadata/Activate/Deactivate, no hard
 * delete), because — unlike S05's structure-only families — S06-AUTH-001 gives this catalog's
 * concrete values explicitly (seeded by a later migration, not here).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ref.monthly_cadre_categories', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('code', 64);
            $table->string('name_ar');
            $table->string('name_en')->nullable();
            $table->boolean('is_active')->default(true);
            $table->smallInteger('display_order')->default(0);
            $table->unsignedInteger('version')->default(1);
            $table->timestampTz('created_at');
            $table->timestampTz('updated_at');

            $table->unique('code', 'monthly_cadre_categories_code_unique');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE "ref"."monthly_cadre_categories"
                ADD CONSTRAINT "monthly_cadre_categories_version_check"
                CHECK ("version" >= 1)
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('ref.monthly_cadre_categories');
    }
};
