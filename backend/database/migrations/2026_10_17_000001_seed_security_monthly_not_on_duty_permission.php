<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Adds the one S39 permission row (docs/monthly-not-on-duty-report-foundation-specification.md §S39.10, §S39.17), following the
 * S31/S38 precedent: a later stage's permissions are added through a new migration, never by editing a released one. Default
 * deny is unaffected; the row is granted to no role and grants nothing else. The read API is plain RBAC (no organizational scope).
 * This is permission registration only — REPORT-3 has no persistence of its own.
 */
return new class extends Migration
{
    /** @return list<array{code: string, module: string, description: string}> */
    private function monthlyNotOnDutyPermissions(): array
    {
        return [
            ['code' => 'hr.monthly_not_on_duty.view', 'module' => 'human_resources', 'description' => 'View the monthly not-on-duty report.'],
        ];
    }

    public function up(): void
    {
        $now = now();

        foreach ($this->monthlyNotOnDutyPermissions() as $permission) {
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
        DB::table('security.permissions')->whereIn('code', array_column($this->monthlyNotOnDutyPermissions(), 'code'))->delete();
    }
};
