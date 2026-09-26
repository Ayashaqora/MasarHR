<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Adds the five S09 HumanResources-module permission rows to the existing security.permissions
 * catalog (spec §17), following the exact precedent of 2026_09_27_000002_seed_security_
 * organization_permissions / 2026_09_26_000017_seed_security_reference_permissions: a later
 * stage's permissions are added through a new migration, never by editing a released one.
 * Default deny is unaffected — no role is granted any of these by this migration.
 */
return new class extends Migration
{
    /** @return list<array{code: string, module: string, description: string}> */
    private function humanResourcesPermissions(): array
    {
        return [
            ['code' => 'hr.persons.view', 'module' => 'human_resources', 'description' => 'View Person records, including National-ID lookup.'],
            ['code' => 'hr.persons.create', 'module' => 'human_resources', 'description' => 'Create a Person record.'],
            ['code' => 'hr.employment_relationships.view', 'module' => 'human_resources', 'description' => "List a Person's Employment Relationships."],
            ['code' => 'hr.employment_relationships.create', 'module' => 'human_resources', 'description' => 'Create an Employment Relationship for an existing Person.'],
            ['code' => 'hr.employment_relationships.end', 'module' => 'human_resources', 'description' => 'End an Employment Relationship.'],
        ];
    }

    public function up(): void
    {
        $now = now();

        foreach ($this->humanResourcesPermissions() as $permission) {
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
        $codes = array_column($this->humanResourcesPermissions(), 'code');

        DB::table('security.permissions')->whereIn('code', $codes)->delete();
    }
};
