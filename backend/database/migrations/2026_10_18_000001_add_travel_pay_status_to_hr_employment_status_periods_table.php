<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * S41 SCHEMA-01 (docs/human-cadre-report-foundation-specification.md §S41.5, R1-D36/D48): the explicit pay indicator of a
 * TRAVELING status period. A nullable constrained string — never a native ENUM, never a default, and existing rows stay NULL
 * (NULL is "not recorded": for service it counts by default and is surfaced as TRAVEL_PAY_STATUS_NOT_RECORDED; it is never
 * rewritten to PAID). It is meaningful only for the `traveling` status detail; that applicability is enforced by the write
 * command, because the status code lives in ref.employment_status_details and a CHECK cannot read another table. No index,
 * no foreign key, no trigger and no denormalized status code.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE "hr"."employment_status_periods" ADD COLUMN "travel_pay_status" varchar(16) NULL');

        DB::statement(<<<'SQL'
            ALTER TABLE "hr"."employment_status_periods"
                ADD CONSTRAINT "employment_status_periods_travel_pay_status_check"
                CHECK ("travel_pay_status" IS NULL OR "travel_pay_status" IN ('PAID', 'UNPAID'))
            SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE "hr"."employment_status_periods" DROP CONSTRAINT IF EXISTS "employment_status_periods_travel_pay_status_check"');
        DB::statement('ALTER TABLE "hr"."employment_status_periods" DROP COLUMN IF EXISTS "travel_pay_status"');
    }
};
