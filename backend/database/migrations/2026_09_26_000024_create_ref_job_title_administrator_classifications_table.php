<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ref.job_title_administrator_classifications — effective-dated, exclusive classification of a
 * JobTitle as "administrator" (Report 2, "الإداريين", S06-AUTH-001 §4.3) or not (S06 spec §12.3).
 * A boolean target, not a foreign key to a new catalog: S06-AUTH-001 names exactly one
 * classification value, and adding sibling values would be inventing a category catalog, forbidden
 * by §9 of S06-AUTH-001. Structure only: ref.job_titles is still empty (S05 structure-only), so
 * this migration seeds no rows.
 *
 * Same append-only, half-open [effective_from, effective_to) pattern as
 * ref.specialty_cadre_category_mappings, GiST EXCLUDE keyed on job_title_id alone.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ref.job_title_administrator_classifications', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('job_title_id');
            $table->boolean('is_administrator');
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->timestampTz('created_at');

            $table->foreign('job_title_id', 'job_title_admin_classifications_job_title_fk')
                ->references('id')->on('ref.job_titles');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE "ref"."job_title_administrator_classifications"
                ADD CONSTRAINT "job_title_admin_classifications_period_check"
                CHECK ("effective_to" IS NULL OR "effective_to" > "effective_from")
            SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE "ref"."job_title_administrator_classifications"
                ADD CONSTRAINT "job_title_admin_classifications_no_overlap"
                EXCLUDE USING gist (
                    "job_title_id" WITH =,
                    daterange("effective_from", "effective_to", '[)') WITH &&
                )
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('ref.job_title_administrator_classifications');
    }
};
