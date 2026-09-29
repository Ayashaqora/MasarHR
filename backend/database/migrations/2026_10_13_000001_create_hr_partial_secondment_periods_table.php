<?php

use App\Modules\Platform\Infrastructure\Persistence\Postgres\TemporalConstraints;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * hr.partial_secondment_periods + hr.partial_secondment_period_weekdays — S30's Partial Secondment
 * Foundation (docs/partial-secondment-foundation-specification.md §S30.6, ADR-S30-001/002/003/005).
 * A Partial Secondment is a temporal child of the S09 Employment Relationship aggregate — never of
 * Person — with the S12 column shape (relationship, destination unit, half-open DATE period,
 * CHECK on the interval, RESTRICT FKs). Append-only: no version / updated_at.
 *
 * Unlike every earlier temporal stream there is deliberately NO date-only EXCLUDE on the parent:
 * two Partial Secondments of the same relationship may overlap in time when their allocated
 * weekdays are disjoint (ADR-S30-005). The authoritative guard is instead on the weekday
 * membership rows, which carry a denormalized copy of the owning relationship and of the period's
 * daterange:
 *
 *  - the parent exposes `period` as a STORED generated daterange(effective_from, effective_to, '[)')
 *    plus UNIQUE (id, employment_relationship_id, period);
 *  - each membership row references that triple through a composite FK that is DEFERRABLE
 *    INITIALLY DEFERRED (plain NO ACTION — the S02 convention forbids CASCADE in migrations): a
 *    transaction that changes a parent's period must re-copy it onto the membership rows
 *    (PartialSecondmentPeriod does so on every update) or PostgreSQL rejects it at commit, so a
 *    committed copy can never differ from its parent;
 *  - EXCLUDE USING gist (employment_relationship_id WITH =, weekday_id WITH =, period WITH &&)
 *    rejects "same relationship + overlapping dates + same weekday" in PostgreSQL itself.
 *
 * Membership is explicit relational rows (never a bitmask or localized text) referencing S29's
 * structural ref.weekdays; the composite primary key forbids a duplicate weekday. "At least one
 * weekday" is enforced by RecordPartialSecondmentPeriod inside the same transaction.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr.partial_secondment_periods', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('employment_relationship_id');
            $table->uuid('organizational_unit_id');
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->timestampTz('created_at');

            $table->foreign('employment_relationship_id', 'partial_secondment_periods_relationship_fk')
                ->references('id')->on('hr.employment_relationships')->restrictOnDelete();
            $table->foreign('organizational_unit_id', 'partial_secondment_periods_unit_fk')
                ->references('id')->on('org.organizational_units')->restrictOnDelete();

            $table->index('employment_relationship_id', 'partial_secondment_periods_relationship_id_index');
        });

        DB::statement(TemporalConstraints::validPeriodCheckSql(
            'hr.partial_secondment_periods',
            'partial_secondment_periods_period_check',
        ));

        DB::statement(sprintf(
            'ALTER TABLE "hr"."partial_secondment_periods" ADD COLUMN "period" daterange GENERATED ALWAYS AS (%s) STORED',
            TemporalConstraints::rangeExpression(),
        ));
        DB::statement('ALTER TABLE "hr"."partial_secondment_periods" ADD CONSTRAINT "partial_secondment_periods_owner_period_unique" UNIQUE ("id", "employment_relationship_id", "period")');

        Schema::create('hr.partial_secondment_period_weekdays', function (Blueprint $table): void {
            $table->uuid('partial_secondment_period_id');
            $table->uuid('weekday_id');
            $table->uuid('employment_relationship_id');

            $table->primary(['partial_secondment_period_id', 'weekday_id'], 'partial_secondment_period_weekdays_pkey');
            $table->foreign('weekday_id', 'partial_secondment_period_weekdays_weekday_fk')
                ->references('id')->on('ref.weekdays')->restrictOnDelete();
            $table->index('weekday_id', 'partial_secondment_period_weekdays_weekday_id_index');
        });

        DB::statement('ALTER TABLE "hr"."partial_secondment_period_weekdays" ADD COLUMN "period" daterange NOT NULL');
        DB::statement(<<<'SQL'
            ALTER TABLE "hr"."partial_secondment_period_weekdays"
                ADD CONSTRAINT "partial_secondment_period_weekdays_period_fk"
                FOREIGN KEY ("partial_secondment_period_id", "employment_relationship_id", "period")
                REFERENCES "hr"."partial_secondment_periods" ("id", "employment_relationship_id", "period")
                ON DELETE RESTRICT DEFERRABLE INITIALLY DEFERRED
            SQL);
        DB::statement(<<<'SQL'
            ALTER TABLE "hr"."partial_secondment_period_weekdays"
                ADD CONSTRAINT "partial_secondment_period_weekdays_no_overlap"
                EXCLUDE USING gist ("employment_relationship_id" WITH =, "weekday_id" WITH =, "period" WITH &&)
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('hr.partial_secondment_period_weekdays');
        Schema::dropIfExists('hr.partial_secondment_periods');
    }
};
