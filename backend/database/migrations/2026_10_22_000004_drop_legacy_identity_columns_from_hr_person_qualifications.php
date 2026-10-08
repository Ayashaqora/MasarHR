<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * S48 step 5 of §S48.18's cutover plan (docs/person-qualification-history-foundation-specification.md
 * §S48.3/§S48.13/§S48.18, D13/D20): once every consumer in §S48.13 is repointed to
 * `hr.person_qualifications_current`/`hr.person_qualification_versions` (this same change set),
 * the original `academic_degree_id`/`qualification_type_id` columns and their now-superseded
 * constraints are dropped from `hr.person_qualifications` — never left in place "for now" beside
 * the new table (D20). This is the last step of the migration file sequence; §S48.18's real
 * maintenance-window plan (documented separately, not executed against any real database this
 * round) sequences the equivalent of this step against the application code deploy, inside the
 * same halted-traffic window the backfill opened.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Raw, schema-qualified SQL throughout (not Schema::table()'s dropIndex/dropForeign):
        // DROP INDEX/DROP CONSTRAINT are schema objects resolved via search_path, which does not
        // include "hr" for this connection (only "$user", public) — Blueprint's dropIndex() emits
        // an unqualified index name and fails to find it.
        DB::statement('ALTER TABLE "hr"."person_qualifications" DROP CONSTRAINT IF EXISTS "person_qualifications_identity_present_check"');
        DB::statement('ALTER TABLE "hr"."person_qualifications" DROP CONSTRAINT IF EXISTS "person_qualifications_identity_unique"');
        DB::statement('ALTER TABLE "hr"."person_qualifications" DROP CONSTRAINT IF EXISTS "person_qualifications_academic_degree_fk"');
        DB::statement('ALTER TABLE "hr"."person_qualifications" DROP CONSTRAINT IF EXISTS "person_qualifications_qualification_type_fk"');
        DB::statement('DROP INDEX IF EXISTS "hr"."person_qualifications_academic_degree_id_index"');
        DB::statement('DROP INDEX IF EXISTS "hr"."person_qualifications_qualification_type_id_index"');
        DB::statement('ALTER TABLE "hr"."person_qualifications" DROP COLUMN IF EXISTS "academic_degree_id"');
        DB::statement('ALTER TABLE "hr"."person_qualifications" DROP COLUMN IF EXISTS "qualification_type_id"');
    }

    public function down(): void
    {
        // S48 rollback gate (§S48.18, corrected gate D42, precision corrected D44) — the SAME
        // check (byte-for-byte) as 2026_10_22_000002's and 2026_10_22_000003's own down(), each
        // repeated independently rather than shared (anonymous classes, no autoloaded common
        // base, composer.json out of scope for this change — see 000002's down() for the full
        // rationale). Rollback runs most-recent-migration-first, so THIS file's down() is the
        // FIRST to execute in the S48 rollback chain; it must refuse before re-adding any column,
        // restoring any value, or re-adding any constraint below — not only once the chain
        // eventually reaches 000002.
        $liveWriteExists = DB::selectOne(<<<'SQL'
            SELECT EXISTS (
                SELECT 1 FROM hr.person_qualification_versions WHERE created_by_principal_id IS NOT NULL
            ) AS exists_flag
            SQL)->exists_flag;

        if ($liveWriteExists) {
            throw new RuntimeException(
                'S48 rollback refused: at least one hr.person_qualification_versions row has a '.
                'known created_by_principal_id (from the backfill resolving a real actor, or from '.
                'a genuine post-migration write). Reverting would silently destroy real '.
                'obtained_on/actor data. Refused before any schema, permission, version-data, or '.
                'migrations-record change — no partial reversal is performed.'
            );
        }

        Schema::table('hr.person_qualifications', function ($table): void {
            $table->uuid('academic_degree_id')->nullable();
            $table->uuid('qualification_type_id')->nullable();
        });

        // Restore the parent row's own values from each qualification's current version — the
        // only source left once the columns were dropped.
        DB::statement(<<<'SQL'
            UPDATE hr.person_qualifications pq
            SET academic_degree_id = v.academic_degree_id, qualification_type_id = v.qualification_type_id
            FROM hr.person_qualification_versions v
            WHERE v.person_qualification_id = pq.id AND v.is_current
            SQL);

        Schema::table('hr.person_qualifications', function ($table): void {
            $table->foreign('academic_degree_id', 'person_qualifications_academic_degree_fk')
                ->references('id')->on('ref.academic_degrees')->restrictOnDelete();
            $table->foreign('qualification_type_id', 'person_qualifications_qualification_type_fk')
                ->references('id')->on('ref.qualification_types')->restrictOnDelete();
            $table->index('academic_degree_id', 'person_qualifications_academic_degree_id_index');
            $table->index('qualification_type_id', 'person_qualifications_qualification_type_id_index');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE "hr"."person_qualifications"
                ADD CONSTRAINT "person_qualifications_identity_present_check"
                CHECK ("academic_degree_id" IS NOT NULL OR "qualification_type_id" IS NOT NULL)
            SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE "hr"."person_qualifications"
                ADD CONSTRAINT "person_qualifications_identity_unique"
                UNIQUE NULLS NOT DISTINCT ("person_id", "academic_degree_id", "qualification_type_id")
            SQL);
    }
};
