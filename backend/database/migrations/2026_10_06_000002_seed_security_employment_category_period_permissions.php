<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Adds the two S20 permission rows to the existing security.permissions catalog
 * (docs/employment-category-history-foundation-specification.md §S20.12), following the exact
 * precedent of 2026_09_30_000002_seed_security_employment_status_period_permissions: a later
 * stage's permissions are added through a new migration, never by editing a released one. Default
 * deny is unaffected — no role is granted either of these by this migration.
 *
 * These are hr.* permissions over the temporal employment fact, deliberately distinct from the
 * reference.* permissions that administer the ref.employment_categories catalog itself. Plain
 * RBAC only — the same relationship-level precedent S10's two status-period permissions
 * established (ADR-S20-001 §8); no S08 organizational-scope composition is introduced here.
 */
return new class extends Migration
{
    /** @return list<array{code: string, module: string, description: string}> */
    private function employmentCategoryPeriodPermissions(): array
    {
        return [
            ['code' => 'hr.employment_category_periods.view', 'module' => 'human_resources', 'description' => "List an Employment Relationship's employment category history."],
            ['code' => 'hr.employment_category_periods.record', 'module' => 'human_resources', 'description' => 'Record a new employment category period for an Employment Relationship.'],
        ];
    }

    public function up(): void
    {
        $now = now();

        foreach ($this->employmentCategoryPeriodPermissions() as $permission) {
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
        $codes = array_column($this->employmentCategoryPeriodPermissions(), 'code');

        DB::table('security.permissions')->whereIn('code', $codes)->delete();
    }
};
