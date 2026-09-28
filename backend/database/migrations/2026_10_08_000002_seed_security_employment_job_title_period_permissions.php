<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Adds the two S22 permission rows to the existing security.permissions catalog
 * (docs/employment-job-title-history-foundation-specification.md §S22.13), following the exact
 * precedent of the S20/S21 permission seeds: a later stage's permissions are added through a new
 * migration, never by editing a released one. Default deny is unaffected.
 *
 * hr.* permissions over the temporal employment fact — deliberately distinct from the reference.*
 * permissions that administer the ref.job_titles catalog itself. Plain RBAC only, the same
 * relationship-level precedent as S10/S20/S21 (ADR-S22-001 §9).
 */
return new class extends Migration
{
    /** @return list<array{code: string, module: string, description: string}> */
    private function employmentJobTitlePeriodPermissions(): array
    {
        return [
            ['code' => 'hr.employment_job_title_periods.view', 'module' => 'human_resources', 'description' => "List an Employment Relationship's employment job title history."],
            ['code' => 'hr.employment_job_title_periods.record', 'module' => 'human_resources', 'description' => 'Record a new employment job title period for an Employment Relationship.'],
        ];
    }

    public function up(): void
    {
        $now = now();

        foreach ($this->employmentJobTitlePeriodPermissions() as $permission) {
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
        $codes = array_column($this->employmentJobTitlePeriodPermissions(), 'code');

        DB::table('security.permissions')->whereIn('code', $codes)->delete();
    }
};
