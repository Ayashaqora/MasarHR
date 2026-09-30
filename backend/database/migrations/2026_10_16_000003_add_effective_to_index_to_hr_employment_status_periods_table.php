<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The one index S38's scanner query justifies (docs/employment-status-expiry-followup-specification.md §S38.14).
 * hr.employment_status_periods had no standalone effective_to index: its candidate discovery (a range on
 * effective_to, (D, D + 7]) sequentially scanned ALL status history, so its cost grew with the history. Measured
 * on 180,000 synthetic periods (60,000 relationships): a Parallel Seq Scan of 2,046 buffers / ~16 ms became an Index
 * Scan of 355 buffers / ~0.9 ms, the same 352 candidates. PARTIAL (effective_to IS NOT NULL) because only bounded
 * periods are candidates — open-ended rows, the majority, are not indexed. No data is touched.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE INDEX "employment_status_periods_effective_to_index" ON "hr"."employment_status_periods" ("effective_to") WHERE "effective_to" IS NOT NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS "hr"."employment_status_periods_effective_to_index"');
    }
};
