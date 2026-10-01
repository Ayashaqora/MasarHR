<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * S41 SCHEMA-02 (docs/human-cadre-report-foundation-specification.md §S41.6, R1-D33/D42/D44/D45): the Primary Qualification
 * designation. `is_primary` is NOT NULL DEFAULT false and at most ONE row per Person may be true — a PostgreSQL partial
 * unique index is the final invariant (0..1 Primary per Person; a Person with qualifications and no Primary is valid storage).
 *
 * Backfill (D42): a Person with exactly ONE qualification gets that row as Primary; a Person with two or more keeps ALL
 * false — a Primary is never inferred among several rows. The backfill selects only single-row Persons, so it cannot create
 * a second Primary for any Person; the unique index is created after it and would reject one regardless.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE "hr"."person_qualifications" ADD COLUMN "is_primary" boolean NOT NULL DEFAULT false');

        DB::statement(<<<'SQL'
            UPDATE "hr"."person_qualifications"
            SET "is_primary" = true
            WHERE "person_id" IN (
                SELECT "person_id" FROM "hr"."person_qualifications" GROUP BY "person_id" HAVING count(*) = 1
            )
            SQL);

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX "person_qualifications_one_primary_unique"
                ON "hr"."person_qualifications" ("person_id")
                WHERE "is_primary" = true
            SQL);
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS "hr"."person_qualifications_one_primary_unique"');
        DB::statement('ALTER TABLE "hr"."person_qualifications" DROP COLUMN IF EXISTS "is_primary"');
    }
};
