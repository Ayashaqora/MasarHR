<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ref.marital_statuses — Marital status reference values (S05 §5.2/§22).
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
        Schema::create('ref.marital_statuses', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('code', 64);
            $table->string('name_ar');
            $table->string('name_en')->nullable();
            $table->boolean('is_active')->default(true);
            $table->smallInteger('display_order')->default(0);
            $table->unsignedInteger('version')->default(1);
            $table->timestampTz('created_at');
            $table->timestampTz('updated_at');

            $table->unique('code', 'marital_statuses_code_unique');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE "ref"."marital_statuses"
                ADD CONSTRAINT "marital_statuses_version_check"
                CHECK ("version" >= 1)
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('ref.marital_statuses');
    }
};
