<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ref.specialty_cadre_category_mappings — effective-dated, exclusive mapping from a Specialty to
 * its Monthly Human Cadre reporting category (S06 spec §12.2). Structure only: ref.specialties is
 * still empty (S05 structure-only, values deferred), so this migration seeds no rows — a future
 * stage populates both once Specialty values are authorized.
 *
 * Same append-only, half-open [effective_from, effective_to) pattern as
 * ref.employment_status_detail_behaviors (S06 spec §9): the GiST EXCLUDE constraint below, keyed
 * on specialty_id alone, makes it physically impossible for one specialty to belong to two cadre
 * categories at overlapping dates — this is what "exclusive" (§12.2 point 3) means at the database
 * level, independent of any application check.
 *
 * All constraint/foreign-key names below are explicit and manually shortened (never Laravel's
 * auto-generated default) — caught during the S06 adversarial self-review (spec §28 item 22)
 * before implementation, since a fully-qualified default name here would risk PostgreSQL's 63-byte
 * identifier limit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ref.specialty_cadre_category_mappings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('specialty_id');
            $table->uuid('cadre_category_id');
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->timestampTz('created_at');

            $table->foreign('specialty_id', 'specialty_cadre_mappings_specialty_fk')
                ->references('id')->on('ref.specialties');
            $table->foreign('cadre_category_id', 'specialty_cadre_mappings_category_fk')
                ->references('id')->on('ref.monthly_cadre_categories');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE "ref"."specialty_cadre_category_mappings"
                ADD CONSTRAINT "specialty_cadre_mappings_period_check"
                CHECK ("effective_to" IS NULL OR "effective_to" > "effective_from")
            SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE "ref"."specialty_cadre_category_mappings"
                ADD CONSTRAINT "specialty_cadre_mappings_no_overlap"
                EXCLUDE USING gist (
                    "specialty_id" WITH =,
                    daterange("effective_from", "effective_to", '[)') WITH &&
                )
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('ref.specialty_cadre_category_mappings');
    }
};
