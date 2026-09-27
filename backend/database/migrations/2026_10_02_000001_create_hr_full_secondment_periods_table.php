<?php

use App\Modules\Platform\Infrastructure\Persistence\Postgres\TemporalConstraints;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * hr.full_secondment_periods — S12's Full Secondment Foundation
 * (docs/full-secondment-foundation-specification.md §8/§10): a temporal child of the S09
 * Employment Relationship aggregate, never its own aggregate root — the same shape as S10's
 * hr.employment_status_periods and S11's hr.organizational_placement_periods. Ties a concrete
 * hr.employment_relationships row to an org.organizational_units row (S07) for the duration of a
 * temporary "full secondment" — the original workplace stream (S11) is never written by this
 * table. Named `full_secondment_periods`, not `secondment_periods`: spec §7.1 discloses why a
 * speculative `type` discriminator for a still-unbuilt Partial Secondment variant is not added
 * here.
 *
 * Unlike S10/S11's "close-then-open" transition shape, starting a new period here (via
 * StartFullSecondment) never auto-closes an existing open one — spec §8.1 — so no row is ever
 * updated as a side effect of inserting another; the only mutation any row ever receives is its
 * own `effective_to` being set once, by EndFullSecondment, in a separate, deliberate action.
 *
 * No `version` column: identical reasoning to hr.organizational_placement_periods (S11) — a
 * period is never independently re-submitted by a client with an expected version.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr.full_secondment_periods', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('employment_relationship_id');
            $table->uuid('organizational_unit_id');
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->timestampTz('created_at');

            $table->foreign('employment_relationship_id', 'full_secondment_periods_relationship_fk')
                ->references('id')->on('hr.employment_relationships')->restrictOnDelete();
            $table->foreign('organizational_unit_id', 'full_secondment_periods_unit_fk')
                ->references('id')->on('org.organizational_units')->restrictOnDelete();

            $table->index('employment_relationship_id', 'full_secondment_periods_relationship_id_index');
        });

        DB::statement(TemporalConstraints::validPeriodCheckSql(
            'hr.full_secondment_periods',
            'full_secondment_periods_period_check',
        ));

        DB::statement(TemporalConstraints::noOverlapConstraintSql(
            'hr.full_secondment_periods',
            'full_secondment_periods_no_overlap',
            ['employment_relationship_id'],
        ));
    }

    public function down(): void
    {
        Schema::dropIfExists('hr.full_secondment_periods');
    }
};
