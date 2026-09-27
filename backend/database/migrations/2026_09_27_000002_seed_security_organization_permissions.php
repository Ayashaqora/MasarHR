<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Adds the two S07 Organization-module permission rows to the existing security.permissions catalog
 * (spec §17). Per the exact S05 precedent (2026_09_26_000017_seed_security_reference_permissions),
 * a later stage's permissions are added through a new migration; this migration never edits that
 * S05 migration, the S03 baseline-permissions migration, or any other released migration. Default
 * deny is unaffected: no existing role is granted either permission by this migration.
 */
return new class extends Migration
{
    /** @return list<array{code: string, module: string, description: string}> */
    private function organizationPermissions(): array
    {
        return [
            ['code' => 'organization.view', 'module' => 'organization', 'description' => 'View the organizational-unit hierarchy.'],
            ['code' => 'organization.manage', 'module' => 'organization', 'description' => 'Create, rename, move, activate, and deactivate organizational units.'],
        ];
    }

    public function up(): void
    {
        $now = now();

        foreach ($this->organizationPermissions() as $permission) {
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
        $codes = array_column($this->organizationPermissions(), 'code');

        DB::table('security.permissions')->whereIn('code', $codes)->delete();
    }
};
