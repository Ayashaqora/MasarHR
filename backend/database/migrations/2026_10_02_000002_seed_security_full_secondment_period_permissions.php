<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Adds the three S12 permission rows to the existing security.permissions catalog
 * (docs/full-secondment-foundation-specification.md §12), following the exact precedent of
 * 2026_10_01_000002_seed_security_organizational_placement_period_permissions: a later stage's
 * permissions are added through a new migration, never by editing a released one. Default deny is
 * unaffected — no role is granted any of these by this migration. Separate start/end permissions
 * mirror S09's own EMPLOYMENT_RELATIONSHIPS_CREATE/EMPLOYMENT_RELATIONSHIPS_END split. All three
 * additionally require S08 organizational scope over one or two target units depending on the
 * operation (spec §12.1) — that composition happens in the controller via
 * ScopedAuthorizationChecker, not here.
 */
return new class extends Migration
{
    /** @return list<array{code: string, module: string, description: string}> */
    private function fullSecondmentPeriodPermissions(): array
    {
        return [
            ['code' => 'hr.full_secondment_periods.view', 'module' => 'human_resources', 'description' => "List an Employment Relationship's full secondment history and resolve its current actual workplace."],
            ['code' => 'hr.full_secondment_periods.start', 'module' => 'human_resources', 'description' => 'Start a new full secondment period for an Employment Relationship.'],
            ['code' => 'hr.full_secondment_periods.end', 'module' => 'human_resources', 'description' => "End an Employment Relationship's currently active full secondment period."],
        ];
    }

    public function up(): void
    {
        $now = now();

        foreach ($this->fullSecondmentPeriodPermissions() as $permission) {
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
        $codes = array_column($this->fullSecondmentPeriodPermissions(), 'code');

        DB::table('security.permissions')->whereIn('code', $codes)->delete();
    }
};
