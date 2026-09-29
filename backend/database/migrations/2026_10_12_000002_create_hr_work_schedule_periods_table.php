<?php

use App\Modules\Platform\Infrastructure\Persistence\Postgres\TemporalConstraints;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * hr.work_schedule_periods + hr.work_schedule_period_weekdays — S29's Work Schedule Foundation
 * (docs/work-schedule-foundation-specification.md §S29.6, ADR-S29-001/004). A Work Schedule is a
 * temporal child of the S09 Employment Relationship aggregate — never of Person, never an
 * organizational default — with the S20/S22/S26 column shape: half-open DATE periods, a CHECK on
 * the interval, and a GiST EXCLUDE so at most one schedule is effective per relationship per date
 * (adjacency allowed). Append-only: no version / updated_at.
 *
 * The selected weekdays are explicit relational membership rows (never a bitmask, never localized
 * text): composite primary key (period, weekday) forbids duplicate membership; the RESTRICT FK to
 * ref.weekdays protects the structural identities. No hours, shifts, attendance, allocation or
 * workplace column exists. "At least one weekday" is enforced by RecordWorkSchedulePeriod inside
 * the same transaction that inserts the period.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr.work_schedule_periods', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('employment_relationship_id');
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->timestampTz('created_at');

            $table->foreign('employment_relationship_id', 'work_schedule_periods_relationship_fk')
                ->references('id')->on('hr.employment_relationships')->restrictOnDelete();
            $table->index('employment_relationship_id', 'work_schedule_periods_relationship_id_index');
        });

        DB::statement(TemporalConstraints::validPeriodCheckSql(
            'hr.work_schedule_periods',
            'work_schedule_periods_period_check',
        ));

        DB::statement(TemporalConstraints::noOverlapConstraintSql(
            'hr.work_schedule_periods',
            'work_schedule_periods_no_overlap',
            ['employment_relationship_id'],
        ));

        Schema::create('hr.work_schedule_period_weekdays', function (Blueprint $table): void {
            $table->uuid('work_schedule_period_id');
            $table->uuid('weekday_id');

            $table->primary(['work_schedule_period_id', 'weekday_id'], 'work_schedule_period_weekdays_pkey');
            $table->foreign('work_schedule_period_id', 'work_schedule_period_weekdays_period_fk')
                ->references('id')->on('hr.work_schedule_periods')->restrictOnDelete();
            $table->foreign('weekday_id', 'work_schedule_period_weekdays_weekday_fk')
                ->references('id')->on('ref.weekdays')->restrictOnDelete();
            $table->index('weekday_id', 'work_schedule_period_weekdays_weekday_id_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr.work_schedule_period_weekdays');
        Schema::dropIfExists('hr.work_schedule_periods');
    }
};
