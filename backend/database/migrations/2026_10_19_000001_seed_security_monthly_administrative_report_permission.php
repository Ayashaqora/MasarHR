<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * S42: the dedicated read permission of the R2 Administrative / Job Title / Gender / Actual Work report
 * (docs/administrative-report-foundation-specification.md §S42.14). Plain RBAC; the seed creates the permission only —
 * it is granted to no role. No table, no column.
 */
return new class extends Migration
{
    private const CODE = 'hr.monthly_administrative_report.view';

    public function up(): void
    {
        DB::table('security.permissions')->insert([
            'id' => (string) Str::uuid7(),
            'code' => self::CODE,
            'module' => 'human_resources',
            'description' => 'View the R2 Administrative / Job Title / Gender / Actual Work report for one month.',
            'created_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('security.permissions')->where('code', self::CODE)->delete();
    }
};
