<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Adds the two S29 permission rows (docs/work-schedule-foundation-specification.md §S29.13),
 * following the S20/S21/S22/S26 precedent: a later stage's permissions are added through a new
 * migration, never by editing a released one. Default deny is unaffected. Plain relationship-level
 * RBAC, the same precedent as S10/S20/S21/S22/S26.
 */
return new class extends Migration
{
    /** @return list<array{code: string, module: string, description: string}> */
    private function workSchedulePeriodPermissions(): array
    {
        return [
            ['code' => 'hr.work_schedule_periods.view', 'module' => 'human_resources', 'description' => "List an Employment Relationship's work schedule history."],
            ['code' => 'hr.work_schedule_periods.record', 'module' => 'human_resources', 'description' => 'Record a new work schedule period for an Employment Relationship.'],
        ];
    }

    public function up(): void
    {
        $now = now();

        foreach ($this->workSchedulePeriodPermissions() as $permission) {
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
        $codes = array_column($this->workSchedulePeriodPermissions(), 'code');

        DB::table('security.permissions')->whereIn('code', $codes)->delete();
    }
};
