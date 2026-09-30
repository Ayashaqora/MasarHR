<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * automation.employment_status_expiry_followups — S38's Temporary Employment Status Expiry Follow-up
 * (docs/employment-status-expiry-followup-specification.md §S38.5, §S38.11). One row is one LOGICAL
 * follow-up: the 7-calendar-day warning that an eligible bounded temporary employment status period
 * is about to end. S38 owns this table, its kind and its lead time; it is deliberately NOT S31's
 * movement table (which stays unchanged) and reuses none of its constraints.
 *
 * Ownership: a real RESTRICT foreign key to the owning hr.employment_status_periods row, and the
 * relationship is protected by a COMPOSITE foreign key (period, relationship) → the period's
 * (id, employment_relationship_id), so the stored relationship can never disagree with the
 * period's own (same technique as S30's partial-secondment weekday membership). That needs one
 * redundant UNIQUE (id, employment_relationship_id) on hr.employment_status_periods — it adds no
 * restriction (id is already the primary key) and touches no data. Rows are never deleted.
 *
 * Logical identity / idempotency: UNIQUE (followup_kind, employment_status_period_id,
 * expected_effective_to). A period whose end changes later would be a DIFFERENT logical follow-up;
 * the old one is suppressed, never rewritten. due_date is CHECKed to be exactly
 * expected_effective_to − 7 calendar days (DATE arithmetic). The lifecycle is two persisted states
 * (LAPSED is derived at read time, never stored); the CHECK keeps state, reason and suppressed_at
 * coherent. No backfill: this migration inserts nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE "hr"."employment_status_periods" ADD CONSTRAINT "employment_status_periods_owner_unique" UNIQUE ("id", "employment_relationship_id")');

        Schema::create('automation.employment_status_expiry_followups', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('followup_kind', 32);
            $table->uuid('employment_status_period_id');
            $table->uuid('employment_relationship_id');
            $table->date('expected_effective_to');
            $table->date('due_date');
            $table->string('status', 16);
            $table->string('suppression_reason', 32)->nullable();
            $table->timestampTz('created_at');
            $table->timestampTz('suppressed_at')->nullable();

            $table->foreign('employment_relationship_id', 'employment_status_expiry_followups_relationship_fk')
                ->references('id')->on('hr.employment_relationships')->restrictOnDelete();

            $table->index('employment_relationship_id', 'employment_status_expiry_followups_relationship_id_index');
            $table->index(['status', 'expected_effective_to'], 'employment_status_expiry_followups_status_end_index');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE "automation"."employment_status_expiry_followups"
                ADD CONSTRAINT "employment_status_expiry_followups_period_owner_fk"
                FOREIGN KEY ("employment_status_period_id", "employment_relationship_id")
                REFERENCES "hr"."employment_status_periods" ("id", "employment_relationship_id")
                ON DELETE RESTRICT
            SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE "automation"."employment_status_expiry_followups"
                ADD CONSTRAINT "employment_status_expiry_followups_period_fk"
                FOREIGN KEY ("employment_status_period_id")
                REFERENCES "hr"."employment_status_periods" ("id")
                ON DELETE RESTRICT
            SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE "automation"."employment_status_expiry_followups"
                ADD CONSTRAINT "employment_status_expiry_followups_logical_key"
                UNIQUE ("followup_kind", "employment_status_period_id", "expected_effective_to")
            SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE "automation"."employment_status_expiry_followups"
                ADD CONSTRAINT "employment_status_expiry_followups_kind_check" CHECK ("followup_kind" IN ('EXPIRY_WARNING_7D')),
                ADD CONSTRAINT "employment_status_expiry_followups_due_date_check" CHECK ("due_date" = "expected_effective_to" - 7),
                ADD CONSTRAINT "employment_status_expiry_followups_status_check" CHECK (
                    ("status" = 'ACTIONABLE' AND "suppression_reason" IS NULL AND "suppressed_at" IS NULL)
                    OR ("status" = 'SUPPRESSED' AND "suppressed_at" IS NOT NULL AND "suppression_reason" IS NOT NULL
                        AND "suppression_reason" IN ('RELATIONSHIP_ENDED', 'TRUNCATED_EARLIER', 'SUCCESSOR_RECORDED'))
                )
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('automation.employment_status_expiry_followups');
        DB::statement('ALTER TABLE "hr"."employment_status_periods" DROP CONSTRAINT IF EXISTS "employment_status_periods_owner_unique"');
    }
};
