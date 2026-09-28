<?php

use App\Modules\Platform\Infrastructure\Persistence\Postgres\TemporalConstraints;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * hr.employment_category_periods — S20's Employment Category History Foundation
 * (docs/employment-category-history-foundation-specification.md §S20.6, ADR-S20-001): a temporal
 * child of the S09 Employment Relationship aggregate, never its own aggregate root and never a
 * child of Person — the category/grade held within one specific employment relationship. Ties a
 * concrete hr.employment_relationships row to an existing ref.employment_categories row (S05
 * structure, S13 seven-grade content); no new category or grade catalog is created.
 *
 * Same column shape as S10's hr.employment_status_periods / S11's
 * hr.organizational_placement_periods (auto-close-on-insert, contiguous history): no `version`
 * column (a period is never independently re-submitted by a client with an expected version) and
 * no `updated_at` (the only mutation a row ever receives is setting `effective_to`, performed by
 * RecordEmploymentCategoryPeriod when the next period is recorded or by EndEmploymentRelationship
 * when the relationship itself ends).
 *
 * No "current category" column is added to hr.employment_relationships — the current category is
 * derived from the period effective on the requested date (spec §S20.7), never stored
 * destructively.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr.employment_category_periods', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('employment_relationship_id');
            $table->uuid('employment_category_id');
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->timestampTz('created_at');

            $table->foreign('employment_relationship_id', 'employment_category_periods_relationship_fk')
                ->references('id')->on('hr.employment_relationships')->restrictOnDelete();
            $table->foreign('employment_category_id', 'employment_category_periods_category_fk')
                ->references('id')->on('ref.employment_categories')->restrictOnDelete();
            $table->index('employment_relationship_id', 'employment_category_periods_relationship_id_index');
            $table->index('employment_category_id', 'employment_category_periods_category_id_index');
        });

        DB::statement(TemporalConstraints::validPeriodCheckSql(
            'hr.employment_category_periods',
            'employment_category_periods_period_check',
        ));

        DB::statement(TemporalConstraints::noOverlapConstraintSql(
            'hr.employment_category_periods',
            'employment_category_periods_no_overlap',
            ['employment_relationship_id'],
        ));
    }

    public function down(): void
    {
        Schema::dropIfExists('hr.employment_category_periods');
    }
};
