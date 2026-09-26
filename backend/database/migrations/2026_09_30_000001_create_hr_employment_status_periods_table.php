<?php

use App\Modules\Platform\Infrastructure\Persistence\Postgres\TemporalConstraints;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * hr.employment_status_periods — S10's Employment Status History
 * (docs/employment-status-history-foundation-specification.md §5/§8): a temporal child of the
 * S09 Employment Relationship aggregate, never its own aggregate root. Ties a concrete
 * hr.employment_relationships row to the S06-seeded ref.employment_status_details catalog over
 * time. Append-only: a row is inserted for the new period, and — as part of the very same
 * RecordEmploymentStatusPeriod transaction — the immediately-prior open period (if any) for the
 * same relationship has its own `effective_to` set; no row is ever otherwise updated or deleted
 * (spec §5/§7).
 *
 * No `version` column: unlike hr.employment_relationships/hr.persons, a period is never
 * independently re-submitted by a client with an expected version — the only mutation any later
 * insert ever causes (closing the prior open period) is fully determined by the transaction that
 * performs it, not by a client-supplied value (spec §5/§9).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr.employment_status_periods', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('employment_relationship_id');
            $table->uuid('status_detail_id');
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->timestampTz('created_at');

            $table->foreign('employment_relationship_id', 'employment_status_periods_relationship_fk')
                ->references('id')->on('hr.employment_relationships')->restrictOnDelete();
            $table->foreign('status_detail_id', 'employment_status_periods_status_detail_fk')
                ->references('id')->on('ref.employment_status_details')->restrictOnDelete();

            $table->index('employment_relationship_id', 'employment_status_periods_relationship_id_index');
        });

        DB::statement(TemporalConstraints::validPeriodCheckSql(
            'hr.employment_status_periods',
            'employment_status_periods_period_check',
        ));

        DB::statement(TemporalConstraints::noOverlapConstraintSql(
            'hr.employment_status_periods',
            'employment_status_periods_no_overlap',
            ['employment_relationship_id'],
        ));
    }

    public function down(): void
    {
        Schema::dropIfExists('hr.employment_status_periods');
    }
};
