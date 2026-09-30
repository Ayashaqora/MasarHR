<?php

use App\Modules\HumanResources\Domain\ReturnIntention;
use App\Modules\HumanResources\Infrastructure\Persistence\Postgres\LegacyReturnIntentionStatusGuard;
use App\Modules\Platform\Infrastructure\Persistence\Postgres\TemporalConstraints;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * hr.return_intention_periods — S34's Return Intention history: a temporal child of the S09
 * Employment Relationship, independent of the employment-status stream (Return Intention is NOT an
 * Employment Status). Half-open DATE periods [effective_from, effective_to); a per-relationship
 * PostgreSQL EXCLUDE forbids overlap; a CHECK restricts `intention` to the two allowed values
 * (absence of a row = NOT RECORDED); RESTRICT FK, no CASCADE. Append-only apart from closing a
 * covering period's `effective_to` by a later explicit record.
 *
 * The fail-closed legacy guard runs FIRST, before anything is created: if any status period still
 * uses a retired legacy code the migration fails and nothing is touched (see the guard class).
 */
return new class extends Migration
{
    public function up(): void
    {
        LegacyReturnIntentionStatusGuard::assertNoLegacyStatusPeriods();

        Schema::create('hr.return_intention_periods', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('employment_relationship_id');
            $table->string('intention', 32);
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->timestampTz('created_at');

            $table->foreign('employment_relationship_id', 'return_intention_periods_relationship_fk')
                ->references('id')->on('hr.employment_relationships')->restrictOnDelete();

            $table->index('employment_relationship_id', 'return_intention_periods_relationship_id_index');
        });

        $values = implode(', ', array_map(fn (string $v) => "'{$v}'", ReturnIntention::values()));
        DB::statement(<<<SQL
            ALTER TABLE hr.return_intention_periods
                ADD CONSTRAINT return_intention_periods_intention_check CHECK ("intention" IN ({$values}))
            SQL);

        DB::statement(TemporalConstraints::validPeriodCheckSql(
            'hr.return_intention_periods',
            'return_intention_periods_period_check',
        ));

        DB::statement(TemporalConstraints::noOverlapConstraintSql(
            'hr.return_intention_periods',
            'return_intention_periods_no_overlap',
            ['employment_relationship_id'],
        ));
    }

    public function down(): void
    {
        Schema::dropIfExists('hr.return_intention_periods');
    }
};
