<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ref.contract_type_population_mappings — effective-dated, exclusive mapping from a ContractType
 * to its Report 4/5 population category (S06 spec §12.5). Structure only: ref.contract_types is
 * still empty (S05 structure-only), so this migration seeds no rows.
 *
 * Same append-only, half-open [effective_from, effective_to) pattern as the other two S06 mapping
 * tables, GiST EXCLUDE keyed on contract_type_id alone.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ref.contract_type_population_mappings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('contract_type_id');
            $table->uuid('population_category_id');
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->timestampTz('created_at');

            $table->foreign('contract_type_id', 'contract_type_population_mappings_type_fk')
                ->references('id')->on('ref.contract_types');
            $table->foreign('population_category_id', 'contract_type_population_mappings_cat_fk')
                ->references('id')->on('ref.contract_based_population_categories');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE "ref"."contract_type_population_mappings"
                ADD CONSTRAINT "contract_type_population_mappings_period_check"
                CHECK ("effective_to" IS NULL OR "effective_to" > "effective_from")
            SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE "ref"."contract_type_population_mappings"
                ADD CONSTRAINT "contract_type_population_mappings_no_overlap"
                EXCLUDE USING gist (
                    "contract_type_id" WITH =,
                    daterange("effective_from", "effective_to", '[)') WITH &&
                )
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('ref.contract_type_population_mappings');
    }
};
