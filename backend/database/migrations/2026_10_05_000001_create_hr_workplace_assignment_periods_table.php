<?php

use App\Modules\Platform\Infrastructure\Persistence\Postgres\TemporalConstraints;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * hr.workplace_assignment_periods — S16's Workplace Assignment Foundation
 * (docs/workplace-assignment-foundation-specification.md §S16.5): a temporal child of the S09
 * Employment Relationship aggregate, never its own aggregate root — the same column shape as
 * S12's hr.full_secondment_periods, chosen over S11's auto-close-on-insert placement shape
 * because Workplace Assignment, like Full Secondment, needs a genuinely separate, deliberate "end
 * with no replacement" action in addition to a "replace" action (spec §S16.9). Ties a concrete
 * hr.employment_relationships row to an org.organizational_units row (S07) for the duration of a
 * temporary workplace assignment ("تكليف") — the original workplace stream (S11) is never written
 * by this table, and this table is never read/written by anything in the Security module's
 * unrelated Role Assignment domain.
 *
 * No `decision_type_id` column — mirrors S14 Transfer's precedent exactly: the decision type is
 * validated at command time and recorded only in the S04 audit entry, never persisted on the
 * period row itself (spec §S16.5).
 *
 * No `version` column: identical reasoning to hr.full_secondment_periods/
 * hr.organizational_placement_periods — a period is never independently re-submitted by a client
 * with an expected version.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr.workplace_assignment_periods', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('employment_relationship_id');
            $table->uuid('organizational_unit_id');
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->timestampTz('created_at');

            $table->foreign('employment_relationship_id', 'workplace_assignment_periods_relationship_fk')
                ->references('id')->on('hr.employment_relationships')->restrictOnDelete();
            $table->foreign('organizational_unit_id', 'workplace_assignment_periods_unit_fk')
                ->references('id')->on('org.organizational_units')->restrictOnDelete();

            $table->index('employment_relationship_id', 'workplace_assignment_periods_relationship_id_index');
        });

        DB::statement(TemporalConstraints::validPeriodCheckSql(
            'hr.workplace_assignment_periods',
            'workplace_assignment_periods_period_check',
        ));

        DB::statement(TemporalConstraints::noOverlapConstraintSql(
            'hr.workplace_assignment_periods',
            'workplace_assignment_periods_no_overlap',
            ['employment_relationship_id'],
        ));
    }

    public function down(): void
    {
        Schema::dropIfExists('hr.workplace_assignment_periods');
    }
};
