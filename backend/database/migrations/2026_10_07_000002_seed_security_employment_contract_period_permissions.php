<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Adds the two S21 permission rows to the existing security.permissions catalog
 * (docs/employment-contract-foundation-specification.md §S21.13), following the exact precedent of
 * 2026_10_06_000002_seed_security_employment_category_period_permissions: a later stage's
 * permissions are added through a new migration, never by editing a released one. Default deny is
 * unaffected — no role is granted either of these by this migration.
 *
 * hr.* permissions over the temporal employment fact — deliberately distinct from the reference.*
 * permissions that administer the ref.contract_types catalog itself. Plain RBAC only, the same
 * relationship-level precedent as S10/S20 (ADR-S21-001 §10).
 */
return new class extends Migration
{
    /** @return list<array{code: string, module: string, description: string}> */
    private function employmentContractPeriodPermissions(): array
    {
        return [
            ['code' => 'hr.employment_contract_periods.view', 'module' => 'human_resources', 'description' => "List an Employment Relationship's employment contract history."],
            ['code' => 'hr.employment_contract_periods.record', 'module' => 'human_resources', 'description' => 'Record an employment contract period (initial contract or renewal) for a CONTRACT Employment Relationship.'],
        ];
    }

    public function up(): void
    {
        $now = now();

        foreach ($this->employmentContractPeriodPermissions() as $permission) {
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
        $codes = array_column($this->employmentContractPeriodPermissions(), 'code');

        DB::table('security.permissions')->whereIn('code', $codes)->delete();
    }
};
