<?php

use App\Modules\Platform\Infrastructure\Persistence\Postgres\TemporalConstraints;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * hr.employment_specialty_periods — S26's Employee Specialty History Foundation
 * (docs/employee-specialty-history-foundation-specification.md §S26.6, ADR-S26-001): a temporal
 * child of the S09 Employment Relationship aggregate — never of Person — recording the specialty
 * held within one specific relationship. References the EXISTING canonical ref.specialties
 * catalog (S05 structure, S25 administration); no second specialty catalog is created, and no
 * qualification, cadre category or other S06 mapping value is stored or cached here.
 *
 * Same column shape and auto-close-on-insert discipline as S20's hr.employment_category_periods /
 * S22's hr.employment_job_title_periods. Deliberately WITHOUT S22's start_knowledge_state marker
 * (ADR-S26-001 I / CA-S26-01): the legacy/import baseline-date policy is deferred, so no import
 * metadata column is added. No `version` / `updated_at` (append-only) and no organizational-unit
 * column (plain relationship-level RBAC, the S22 precedent). No "current specialty" column is
 * added anywhere.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr.employment_specialty_periods', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('employment_relationship_id');
            $table->uuid('specialty_id');
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->timestampTz('created_at');

            $table->foreign('employment_relationship_id', 'employment_specialty_periods_relationship_fk')
                ->references('id')->on('hr.employment_relationships')->restrictOnDelete();
            $table->foreign('specialty_id', 'employment_specialty_periods_specialty_fk')
                ->references('id')->on('ref.specialties')->restrictOnDelete();
            $table->index('employment_relationship_id', 'employment_specialty_periods_relationship_id_index');
            $table->index('specialty_id', 'employment_specialty_periods_specialty_id_index');
        });

        DB::statement(TemporalConstraints::validPeriodCheckSql(
            'hr.employment_specialty_periods',
            'employment_specialty_periods_period_check',
        ));

        DB::statement(TemporalConstraints::noOverlapConstraintSql(
            'hr.employment_specialty_periods',
            'employment_specialty_periods_no_overlap',
            ['employment_relationship_id'],
        ));
    }

    public function down(): void
    {
        Schema::dropIfExists('hr.employment_specialty_periods');
    }
};
