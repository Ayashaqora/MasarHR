<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Adds the two S11 permission rows to the existing security.permissions catalog
 * (docs/organizational-placement-foundation-specification.md §10), following the exact precedent
 * of 2026_09_30_000002_seed_security_employment_status_period_permissions: a later stage's
 * permissions are added through a new migration, never by editing a released one. Default deny
 * is unaffected — no role is granted either of these by this migration. Both permissions still
 * additionally require S08 organizational scope to cover the target unit (spec §10) — that
 * composition happens in the controller via ScopedAuthorizationChecker, not here.
 */
return new class extends Migration
{
    /** @return list<array{code: string, module: string, description: string}> */
    private function organizationalPlacementPeriodPermissions(): array
    {
        return [
            ['code' => 'hr.organizational_placement_periods.view', 'module' => 'human_resources', 'description' => "List an Employment Relationship's organizational placement history."],
            ['code' => 'hr.organizational_placement_periods.record', 'module' => 'human_resources', 'description' => 'Record a new organizational placement period for an Employment Relationship.'],
        ];
    }

    public function up(): void
    {
        $now = now();

        foreach ($this->organizationalPlacementPeriodPermissions() as $permission) {
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
        $codes = array_column($this->organizationalPlacementPeriodPermissions(), 'code');

        DB::table('security.permissions')->whereIn('code', $codes)->delete();
    }
};
