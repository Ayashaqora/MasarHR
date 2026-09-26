<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Adds the two S10 permission rows to the existing security.permissions catalog
 * (docs/employment-status-history-foundation-specification.md §13), following the exact
 * precedent of 2026_09_29_000004_seed_security_human_resources_permissions: a later stage's
 * permissions are added through a new migration, never by editing a released one. Default deny
 * is unaffected — no role is granted either of these by this migration.
 */
return new class extends Migration
{
    /** @return list<array{code: string, module: string, description: string}> */
    private function employmentStatusPeriodPermissions(): array
    {
        return [
            ['code' => 'hr.employment_status_periods.view', 'module' => 'human_resources', 'description' => "List an Employment Relationship's status history."],
            ['code' => 'hr.employment_status_periods.record', 'module' => 'human_resources', 'description' => 'Record a new employment-status transition for an Employment Relationship.'],
        ];
    }

    public function up(): void
    {
        $now = now();

        foreach ($this->employmentStatusPeriodPermissions() as $permission) {
            DB::table('security.permissions')->insert([
                'id' => (string) Str::uuid7(),
                'code' => $permission['code'],
                'module' => $permission['module'],
                'description' => $permission['description'],
                'created_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        $codes = array_column($this->employmentStatusPeriodPermissions(), 'code');

        DB::table('security.permissions')->whereIn('code', $codes)->delete();
    }
};
