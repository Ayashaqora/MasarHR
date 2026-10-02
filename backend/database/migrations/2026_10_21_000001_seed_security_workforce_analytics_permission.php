<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * S44: the dedicated read permission of the Workforce Analytics foundation
 * (docs/workforce-analytics-foundation-specification.md §S44.14). Plain RBAC; the seed creates the permission only —
 * it is granted to no role. No table, no column.
 */
return new class extends Migration
{
    private const CODE = 'hr.workforce_analytics.view';

    public function up(): void
    {
        DB::table('security.permissions')->insert([
            'id' => (string) Str::uuid7(),
            'code' => self::CODE,
            'module' => 'human_resources',
            'description' => 'View the Workforce Analytics foundation for one month.',
            'created_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('security.permissions')->where('code', self::CODE)->delete();
    }
};
