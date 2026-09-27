<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ref.employment_status_details — concrete employment status values (e.g. "on duty", "resigned"),
 * each belonging to exactly one ref.employment_status_categories row (S05 §5.2/§5.4/§20). Same
 * simple-reference-value shape as ref.genders plus the category foreign key. Structure only for
 * S05 — no rows seeded (§22); the behavior contract for a detail lives in the separate,
 * effective-dated ref.employment_status_detail_behaviors table (§12), never on this row directly,
 * so status meaning is never parsed from this table's Arabic label.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ref.employment_status_details', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('category_id');
            $table->string('code', 64);
            $table->string('name_ar');
            $table->string('name_en')->nullable();
            $table->boolean('is_active')->default(true);
            $table->smallInteger('display_order')->default(0);
            $table->unsignedInteger('version')->default(1);
            $table->timestampTz('created_at');
            $table->timestampTz('updated_at');

            $table->unique('code', 'employment_status_details_code_unique');

            $table->foreign('category_id', 'employment_status_details_category_id_foreign')
                ->references('id')->on('ref.employment_status_categories');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE "ref"."employment_status_details"
                ADD CONSTRAINT "employment_status_details_version_check"
                CHECK ("version" >= 1)
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('ref.employment_status_details');
    }
};
