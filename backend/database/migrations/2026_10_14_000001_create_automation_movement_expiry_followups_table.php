<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * automation.movement_expiry_followups — S31's Movement Expiry & Follow-up Foundation
 * (docs/movement-expiry-followup-foundation-specification.md §S31.6, ADR-S31-007/008/009/017/020).
 * One row is one LOGICAL follow-up: the 7-calendar-day warning that a bounded temporary workplace
 * movement (Full Secondment, Workplace Assignment or Partial Secondment period) is about to end.
 * It lives in the already-existing `automation` schema (S02) and never copies employee PII: only
 * ids, dates and stable machine codes.
 *
 * Polymorphic reference WITHOUT a fake polymorphic FK: the three movement streams live in three
 * tables, and PostgreSQL cannot FK one column to three tables, so the row carries three nullable
 * REAL foreign keys (RESTRICT — movement history is never deleted) and a CHECK that exactly one of
 * them is set and agrees with `movement_type` (an "exclusive arc"). `movement_id` is a STORED
 * generated column over that arc, so the logical identity below is one plain UNIQUE constraint.
 *
 * Logical identity / idempotency (ADR-S31-007): UNIQUE (followup_kind, movement_type, movement_id,
 * expected_effective_to). The same logical alert can therefore exist at most once, whatever the
 * scheduler retries or concurrent scans do — PostgreSQL is the backstop, the writer uses
 * INSERT … ON CONFLICT DO NOTHING. A movement whose end date later changes is a DIFFERENT logical
 * follow-up (different expected_effective_to); the old one is suppressed, never rewritten.
 *
 * due_date is CHECKed to be exactly expected_effective_to − 7 calendar days (DATE arithmetic, no
 * working-week logic). The lifecycle is two-state (ADR-S31-009): ACTIONABLE, or SUPPRESSED with a
 * stable reason code; the CHECK keeps status, reason and suppressed_at coherent. No backfill: this
 * migration inserts nothing (ADR-S31-017). Rows are never deleted or edited except the single
 * ACTIONABLE → SUPPRESSED transition.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('automation.movement_expiry_followups', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('followup_kind', 32);
            $table->string('movement_type', 32);
            $table->uuid('full_secondment_period_id')->nullable();
            $table->uuid('workplace_assignment_period_id')->nullable();
            $table->uuid('partial_secondment_period_id')->nullable();
            $table->uuid('employment_relationship_id');
            $table->uuid('organizational_unit_id');
            $table->date('expected_effective_to');
            $table->date('due_date');
            $table->string('status', 16);
            $table->string('suppression_reason', 32)->nullable();
            $table->timestampTz('created_at');
            $table->timestampTz('suppressed_at')->nullable();

            $table->foreign('full_secondment_period_id', 'movement_expiry_followups_full_secondment_fk')
                ->references('id')->on('hr.full_secondment_periods')->restrictOnDelete();
            $table->foreign('workplace_assignment_period_id', 'movement_expiry_followups_assignment_fk')
                ->references('id')->on('hr.workplace_assignment_periods')->restrictOnDelete();
            $table->foreign('partial_secondment_period_id', 'movement_expiry_followups_partial_fk')
                ->references('id')->on('hr.partial_secondment_periods')->restrictOnDelete();
            $table->foreign('employment_relationship_id', 'movement_expiry_followups_relationship_fk')
                ->references('id')->on('hr.employment_relationships')->restrictOnDelete();
            $table->foreign('organizational_unit_id', 'movement_expiry_followups_unit_fk')
                ->references('id')->on('org.organizational_units')->restrictOnDelete();

            $table->index('employment_relationship_id', 'movement_expiry_followups_relationship_id_index');
            $table->index('organizational_unit_id', 'movement_expiry_followups_unit_id_index');
            $table->index(['status', 'expected_effective_to'], 'movement_expiry_followups_status_end_index');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE "automation"."movement_expiry_followups"
                ADD COLUMN "movement_id" uuid GENERATED ALWAYS AS (
                    COALESCE("full_secondment_period_id", "workplace_assignment_period_id", "partial_secondment_period_id")
                ) STORED
            SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE "automation"."movement_expiry_followups"
                ADD CONSTRAINT "movement_expiry_followups_logical_key"
                UNIQUE ("followup_kind", "movement_type", "movement_id", "expected_effective_to")
            SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE "automation"."movement_expiry_followups"
                ADD CONSTRAINT "movement_expiry_followups_kind_check" CHECK ("followup_kind" IN ('EXPIRY_WARNING_7D')),
                ADD CONSTRAINT "movement_expiry_followups_movement_arc_check" CHECK (
                    ("movement_type" = 'FULL_SECONDMENT' AND "full_secondment_period_id" IS NOT NULL
                        AND "workplace_assignment_period_id" IS NULL AND "partial_secondment_period_id" IS NULL)
                    OR ("movement_type" = 'WORKPLACE_ASSIGNMENT' AND "workplace_assignment_period_id" IS NOT NULL
                        AND "full_secondment_period_id" IS NULL AND "partial_secondment_period_id" IS NULL)
                    OR ("movement_type" = 'PARTIAL_SECONDMENT' AND "partial_secondment_period_id" IS NOT NULL
                        AND "full_secondment_period_id" IS NULL AND "workplace_assignment_period_id" IS NULL)
                ),
                ADD CONSTRAINT "movement_expiry_followups_due_date_check" CHECK ("due_date" = "expected_effective_to" - 7),
                ADD CONSTRAINT "movement_expiry_followups_status_check" CHECK (
                    ("status" = 'ACTIONABLE' AND "suppression_reason" IS NULL AND "suppressed_at" IS NULL)
                    OR ("status" = 'SUPPRESSED' AND "suppressed_at" IS NOT NULL AND "suppression_reason" IS NOT NULL
                        AND "suppression_reason" IN ('TRUNCATED_EARLIER', 'END_DATE_CHANGED', 'RELATIONSHIP_ENDED', 'COVERED_BY_NEWER_MOVEMENT'))
                )
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('automation.movement_expiry_followups');
    }
};
