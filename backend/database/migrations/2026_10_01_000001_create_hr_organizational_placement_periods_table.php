<?php

use App\Modules\Platform\Infrastructure\Persistence\Postgres\TemporalConstraints;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * hr.organizational_placement_periods — S11's Organizational Placement Foundation
 * (docs/organizational-placement-foundation-specification.md §6/§8): a temporal child of the
 * S09 Employment Relationship aggregate, never its own aggregate root — the same shape as S10's
 * hr.employment_status_periods, minus any consequence-wiring (Placement never closes, reopens, or
 * otherwise mutates the relationship it belongs to; spec §13). Ties a concrete
 * hr.employment_relationships row to an org.organizational_units row (S07) over time, recording
 * the relationship's "original workplace" only — spec §5.1 discloses why "actual/current
 * workplace" is deliberately not persisted here. Append-only: a row is inserted for the new
 * period, and — as part of the very same RecordOrganizationalPlacementPeriod transaction — the
 * immediately-prior open period (if any) for the same relationship has its own `effective_to`
 * set; no row is ever otherwise updated or deleted.
 *
 * No `version` column: identical reasoning to hr.employment_status_periods (S10) — a period is
 * never independently re-submitted by a client with an expected version.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr.organizational_placement_periods', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('employment_relationship_id');
            $table->uuid('organizational_unit_id');
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->timestampTz('created_at');

            $table->foreign('employment_relationship_id', 'organizational_placement_periods_relationship_fk')
                ->references('id')->on('hr.employment_relationships')->restrictOnDelete();
            $table->foreign('organizational_unit_id', 'organizational_placement_periods_unit_fk')
                ->references('id')->on('org.organizational_units')->restrictOnDelete();

            $table->index('employment_relationship_id', 'organizational_placement_periods_relationship_id_index');
        });

        DB::statement(TemporalConstraints::validPeriodCheckSql(
            'hr.organizational_placement_periods',
            'organizational_placement_periods_period_check',
        ));

        DB::statement(TemporalConstraints::noOverlapConstraintSql(
            'hr.organizational_placement_periods',
            'organizational_placement_periods_no_overlap',
            ['employment_relationship_id'],
        ));
    }

    public function down(): void
    {
        Schema::dropIfExists('hr.organizational_placement_periods');
    }
};
