<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ref.contract_based_population_categories — the two contract-type-derived report populations
 * (Report 4 "Support Services / Daily Workers" and Report 5 "Volunteers / Unemployment",
 * S06-AUTH-001 §4.3). Not a general contract-type catalog — only the two named report population
 * identities those reports' contract-type mappings resolve to (S06 spec §12.4). Same
 * simple-reference-value shape and full CRUD lifecycle as ref.monthly_cadre_categories.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ref.contract_based_population_categories', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('code', 64);
            $table->string('name_ar');
            $table->string('name_en')->nullable();
            $table->boolean('is_active')->default(true);
            $table->smallInteger('display_order')->default(0);
            $table->unsignedInteger('version')->default(1);
            $table->timestampTz('created_at');
            $table->timestampTz('updated_at');

            $table->unique('code', 'contract_based_population_categories_code_unique');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE "ref"."contract_based_population_categories"
                ADD CONSTRAINT "contract_based_population_categories_version_check"
                CHECK ("version" >= 1)
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('ref.contract_based_population_categories');
    }
};
