<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Adds the three S16 permission rows to the existing security.permissions catalog
 * (docs/workplace-assignment-foundation-specification.md §S16.14), following the exact precedent
 * of 2026_10_02_000002_seed_security_full_secondment_period_permissions: a later stage's
 * permissions are added through a new migration, never by editing a released one. Default deny is
 * unaffected — no role is granted any of these by this migration. Separate start/end permissions
 * mirror S12's own FULL_SECONDMENT_PERIODS_START/FULL_SECONDMENT_PERIODS_END split. All three
 * additionally require S08 organizational scope over one or two target units depending on the
 * operation — that composition happens in the controller via ScopedAuthorizationChecker, not
 * here.
 */
return new class extends Migration
{
    /** @return list<array{code: string, module: string, description: string}> */
    private function workplaceAssignmentPeriodPermissions(): array
    {
        return [
            ['code' => 'hr.workplace_assignment_periods.view', 'module' => 'human_resources', 'description' => "List an Employment Relationship's workplace assignment history and resolve its current actual workplace."],
            ['code' => 'hr.workplace_assignment_periods.start', 'module' => 'human_resources', 'description' => 'Start a new workplace assignment period for an Employment Relationship.'],
            ['code' => 'hr.workplace_assignment_periods.end', 'module' => 'human_resources', 'description' => "End an Employment Relationship's currently active workplace assignment period."],
        ];
    }

    public function up(): void
    {
        $now = now();

        foreach ($this->workplaceAssignmentPeriodPermissions() as $permission) {
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
        $codes = array_column($this->workplaceAssignmentPeriodPermissions(), 'code');

        DB::table('security.permissions')->whereIn('code', $codes)->delete();
    }
};
