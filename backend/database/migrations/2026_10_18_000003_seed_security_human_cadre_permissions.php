<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * S41: the two permissions the stage adds (docs/human-cadre-report-foundation-specification.md §S41.14). Both are plain RBAC
 * and granted to no role by this seed. No table.
 */
return new class extends Migration
{
    private function permissions(): array
    {
        return [
            ['code' => 'hr.human_cadre.view', 'module' => 'human_resources', 'description' => 'View the Human Cadre report (REPORT-1): headcount, summaries and Person drilldown for one month.'],
            ['code' => 'hr.person_qualifications.designate_primary', 'module' => 'human_resources', 'description' => "Designate one of a Person's recorded qualifications as the Primary Qualification."],
        ];
    }

    public function up(): void
    {
        $now = now();

        foreach ($this->permissions() as $permission) {
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
        DB::table('security.permissions')->whereIn('code', array_column($this->permissions(), 'code'))->delete();
    }
};
