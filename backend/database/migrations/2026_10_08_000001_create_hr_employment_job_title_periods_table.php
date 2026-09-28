<?php

use App\Modules\Platform\Infrastructure\Persistence\Postgres\TemporalConstraints;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * hr.employment_job_title_periods — S22's Employment Job Title History Foundation
 * (docs/employment-job-title-history-foundation-specification.md §S22.6, ADR-S22-001): a temporal
 * child of the S09 Employment Relationship aggregate — never of Person — recording the EMPLOYMENT
 * job title held within one specific relationship. References the EXISTING ref.job_titles catalog
 * (S05 structure, S13 administration); no second job-title catalog is created. Supervisory titles
 * (ref.supervisory_titles), employment category (S20), specialty and qualification are separate
 * concepts and are not referenced here.
 *
 * Same column shape and auto-close-on-insert discipline as S20's hr.employment_category_periods,
 * plus one import-compatibility marker, start_knowledge_state:
 *  - KNOWN: effective_from is the real start of the title (the only value the API records).
 *  - UNKNOWN_LEGACY: reserved for a future snapshot import — effective_from is the earliest date
 *    the title is actually evidenced by the source, and the real start is unknown (it may be
 *    earlier). The start is never fabricated, and as-of before effective_from stays UNRESOLVED.
 *
 * No `version` / `updated_at` (append-only) and no organizational-unit column (plain
 * relationship-level RBAC, ADR-S22-001 §9). No "current job title" column is added anywhere.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr.employment_job_title_periods', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('employment_relationship_id');
            $table->uuid('job_title_id');
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->string('start_knowledge_state', 16)->default('KNOWN');
            $table->timestampTz('created_at');

            $table->foreign('employment_relationship_id', 'employment_job_title_periods_relationship_fk')
                ->references('id')->on('hr.employment_relationships')->restrictOnDelete();
            $table->foreign('job_title_id', 'employment_job_title_periods_job_title_fk')
                ->references('id')->on('ref.job_titles')->restrictOnDelete();
            $table->index('employment_relationship_id', 'employment_job_title_periods_relationship_id_index');
            $table->index('job_title_id', 'employment_job_title_periods_job_title_id_index');
        });

        DB::statement(TemporalConstraints::validPeriodCheckSql(
            'hr.employment_job_title_periods',
            'employment_job_title_periods_period_check',
        ));

        DB::statement(<<<'SQL'
            ALTER TABLE "hr"."employment_job_title_periods"
                ADD CONSTRAINT "employment_job_title_periods_start_knowledge_state_check"
                CHECK ("start_knowledge_state" IN ('KNOWN', 'UNKNOWN_LEGACY'))
            SQL);

        DB::statement(TemporalConstraints::noOverlapConstraintSql(
            'hr.employment_job_title_periods',
            'employment_job_title_periods_no_overlap',
            ['employment_relationship_id'],
        ));
    }

    public function down(): void
    {
        Schema::dropIfExists('hr.employment_job_title_periods');
    }
};
