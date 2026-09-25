<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ref.employment_status_categories — The 4-tier employment status grouping: ACTIVE / NON_ACTIVE / ENDED / TERMINAL (S05 §5.4).
 *
 * S05 common simple-reference-value shape (see docs/reference-data-foundation-specification.md
 * §20): `code` is the stable, machine-addressed identifier application logic branches on;
 * `name_ar`/`name_en` are display-only and may be changed by UpdateMetadata; `is_active` is
 * lifecycle state (deactivation only — no hard delete is ever exposed, §9); `display_order` is
 * UI sort guidance only, no business meaning; `version` is optimistic-concurrency (mirrors
 * security.roles exactly).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ref.employment_status_categories', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('code', 64);
            $table->string('name_ar');
            $table->string('name_en')->nullable();
            $table->boolean('is_active')->default(true);
            $table->smallInteger('display_order')->default(0);
            $table->unsignedInteger('version')->default(1);
            $table->timestampTz('created_at');
            $table->timestampTz('updated_at');

            $table->unique('code', 'employment_status_categories_code_unique');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE "ref"."employment_status_categories"
                ADD CONSTRAINT "employment_status_categories_version_check"
                CHECK ("version" >= 1)
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('ref.employment_status_categories');
    }
};
