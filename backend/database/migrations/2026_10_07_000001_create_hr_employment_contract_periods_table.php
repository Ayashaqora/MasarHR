<?php

use App\Modules\Platform\Infrastructure\Persistence\Postgres\TemporalConstraints;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * hr.employment_contract_periods — S21's Employment Contract Foundation
 * (docs/employment-contract-foundation-specification.md §S21.6, ADR-S21-001): a temporal child of
 * the S09 Employment Relationship aggregate — never of Person — recording the contract held within
 * one specific CONTRACT-scheme relationship. References the EXISTING ref.contract_types catalog
 * (S05 structure, S13 administration); no second contract-type catalog is created, and
 * ref.employment_types (PERMANENT/CONTRACT appointment type) is a separate concept not referenced
 * here.
 *
 * Two distinct end dates, both EXCLUSIVE (the frozen half-open [from, to) convention):
 *  - contractual_effective_to: the agreed contract term end, recorded once and never rewritten
 *    (NULL only when contract_end_knowledge_state = UNKNOWN_LEGACY — a legacy end that is not
 *    known is never fabricated).
 *  - effective_to: the period's ACTUAL validity end. Set to contractual_effective_to when a
 *    KNOWN-term contract is recorded; only ever moved EARLIER (renewal before term end, or the
 *    relationship ending before term end) — never later than the agreed term, which the
 *    known_term_bounds CHECK makes impossible at the database level. Extension is always a new
 *    period (renewal), never a rewrite.
 *
 * No `version` / `updated_at` (append-only, like the S10/S11/S20 period tables) and no
 * organizational-unit column (plain relationship-level RBAC, ADR-S21-001 §10).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr.employment_contract_periods', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('employment_relationship_id');
            $table->uuid('contract_type_id');
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->date('contractual_effective_to')->nullable();
            $table->string('contract_end_knowledge_state', 16)->default('KNOWN');
            $table->timestampTz('created_at');

            $table->foreign('employment_relationship_id', 'employment_contract_periods_relationship_fk')
                ->references('id')->on('hr.employment_relationships')->restrictOnDelete();
            $table->foreign('contract_type_id', 'employment_contract_periods_contract_type_fk')
                ->references('id')->on('ref.contract_types')->restrictOnDelete();
            $table->index('employment_relationship_id', 'employment_contract_periods_relationship_id_index');
            $table->index('contract_type_id', 'employment_contract_periods_contract_type_id_index');
        });

        DB::statement(TemporalConstraints::validPeriodCheckSql(
            'hr.employment_contract_periods',
            'employment_contract_periods_period_check',
        ));

        // The agreed term itself must be a non-empty interval starting at effective_from.
        DB::statement(TemporalConstraints::validPeriodCheckSql(
            'hr.employment_contract_periods',
            'employment_contract_periods_contractual_period_check',
            'effective_from',
            'contractual_effective_to',
        ));

        // KNOWN term: the contractual end is recorded and actual validity is bounded by it (a
        // contract is never valid past its agreed term). UNKNOWN_LEGACY: no fabricated contractual
        // end; validity may be open or closed by a later lifecycle event.
        DB::statement(<<<'SQL'
            ALTER TABLE "hr"."employment_contract_periods"
                ADD CONSTRAINT "employment_contract_periods_known_term_bounds_check"
                CHECK (
                    ("contract_end_knowledge_state" = 'KNOWN'
                        AND "contractual_effective_to" IS NOT NULL
                        AND "effective_to" IS NOT NULL
                        AND "effective_to" <= "contractual_effective_to")
                    OR ("contract_end_knowledge_state" = 'UNKNOWN_LEGACY'
                        AND "contractual_effective_to" IS NULL)
                )
            SQL);

        DB::statement(TemporalConstraints::noOverlapConstraintSql(
            'hr.employment_contract_periods',
            'employment_contract_periods_no_overlap',
            ['employment_relationship_id'],
        ));
    }

    public function down(): void
    {
        Schema::dropIfExists('hr.employment_contract_periods');
    }
};
