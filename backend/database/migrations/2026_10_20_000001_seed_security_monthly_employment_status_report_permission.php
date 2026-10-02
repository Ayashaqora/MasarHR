<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * S43: the dedicated read permission of the R4 Monthly Employment Status report
 * (docs/employment-status-report-foundation-specification.md §S43.13). Plain RBAC; the seed creates the permission only —
 * it is granted to no role. No table, no column.
 */
return new class extends Migration
{
    private const CODE = 'hr.monthly_employment_status_report.view';

    public function up(): void
    {
        DB::table('security.permissions')->insert([
            'id' => (string) Str::uuid7(),
            'code' => self::CODE,
            'module' => 'human_resources',
            'description' => 'View the R4 Monthly Employment Status report for one month.',
            'created_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('security.permissions')->where('code', self::CODE)->delete();
    }
};
