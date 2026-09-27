<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ref.employment_status_detail_behaviors — effective-dated behavior contract per employment
 * status detail (S05 §11/§12/§20; "already approved physical architecture" per the S05
 * authorization). Append-only history: a period is defined, never updated or deleted (D4). The
 * GiST exclusion constraint below (using the btree_gist extension S02 already enabled) makes
 * overlapping periods for one status detail physically impossible, independent of any
 * application-level check — a violation surfaces as SQLSTATE 23P01, translated at the controller
 * via PostgresErrorClassifier::isExclusionViolation().
 *
 * Half-open interval semantics: effective_to = null means "still in effect"; otherwise the period
 * covers [effective_from, effective_to).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ref.employment_status_detail_behaviors', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('status_detail_id');
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->boolean('participates_in_active_workforce');
            $table->boolean('is_ongoing_relationship');
            $table->boolean('is_relationship_ending');
            $table->boolean('is_terminal');
            $table->boolean('allows_reappointment')->nullable();
            $table->boolean('counts_in_monthly_reporting')->nullable();
            $table->timestampTz('created_at');

            $table->foreign('status_detail_id', 'employment_status_detail_behaviors_detail_id_foreign')
                ->references('id')->on('ref.employment_status_details');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE "ref"."employment_status_detail_behaviors"
                ADD CONSTRAINT "employment_status_detail_behaviors_period_check"
                CHECK ("effective_to" IS NULL OR "effective_to" > "effective_from")
            SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE "ref"."employment_status_detail_behaviors"
                ADD CONSTRAINT "employment_status_detail_behaviors_no_overlap"
                EXCLUDE USING gist (
                    "status_detail_id" WITH =,
                    daterange("effective_from", "effective_to", '[)') WITH &&
                )
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('ref.employment_status_detail_behaviors');
    }
};
