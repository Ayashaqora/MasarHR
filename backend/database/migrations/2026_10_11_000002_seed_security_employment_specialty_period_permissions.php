<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Adds the two S26 permission rows to the existing security.permissions catalog
 * (docs/employee-specialty-history-foundation-specification.md §S26.13), following the exact
 * precedent of the S20/S21/S22 permission seeds: a later stage's permissions are added through a
 * new migration, never by editing a released one. Default deny is unaffected.
 *
 * hr.* permissions over the temporal employment fact — deliberately distinct from the reference.*
 * permissions that administer the ref.specialties catalog itself (S25). Plain RBAC only, the same
 * relationship-level precedent as S10/S20/S21/S22.
 */
return new class extends Migration
{
    /** @return list<array{code: string, module: string, description: string}> */
    private function employmentSpecialtyPeriodPermissions(): array
    {
        return [
            ['code' => 'hr.employment_specialty_periods.view', 'module' => 'human_resources', 'description' => "List an Employment Relationship's employee specialty history."],
            ['code' => 'hr.employment_specialty_periods.record', 'module' => 'human_resources', 'description' => 'Record a new employee specialty period for an Employment Relationship.'],
        ];
    }

    public function up(): void
    {
        $now = now();

        foreach ($this->employmentSpecialtyPeriodPermissions() as $permission) {
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
        $codes = array_column($this->employmentSpecialtyPeriodPermissions(), 'code');

        DB::table('security.permissions')->whereIn('code', $codes)->delete();
    }
};
