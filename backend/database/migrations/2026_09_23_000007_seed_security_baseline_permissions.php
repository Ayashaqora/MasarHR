<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Seeds the S03 baseline Security-module permission catalog (§8 of the S03 authorization).
 *
 * This is reference data owned by the Security module, not HR/business data, so it is seeded by
 * migration rather than left to a seeder that might not run in every environment. The code list
 * here is frozen for this released migration; a later stage adds its own permissions through its
 * own migration rather than editing this one. See
 * App\Modules\Security\Infrastructure\Authorization\PermissionCatalog for the application-facing
 * constants (kept in sync with this list by convention, not by shared code, precisely because this
 * migration must never change once released).
 */
return new class extends Migration
{
    /** @return list<array{code: string, module: string, description: string}> */
    private function baselinePermissions(): array
    {
        return [
            ['code' => 'security.users.view', 'module' => 'security', 'description' => 'View security principals (accounts).'],
            ['code' => 'security.users.create', 'module' => 'security', 'description' => 'Create security principals (accounts).'],
            ['code' => 'security.users.update', 'module' => 'security', 'description' => 'Update principal identity fields and reset passwords administratively.'],
            ['code' => 'security.users.status.manage', 'module' => 'security', 'description' => 'Enable or disable a security principal.'],
            ['code' => 'security.roles.view', 'module' => 'security', 'description' => 'View roles and their granted permissions.'],
            ['code' => 'security.roles.manage', 'module' => 'security', 'description' => 'Create/update roles and grant or revoke their permissions.'],
            ['code' => 'security.role_assignments.manage', 'module' => 'security', 'description' => 'Assign or remove roles on a principal.'],
            ['code' => 'security.permissions.view', 'module' => 'security', 'description' => 'View the permission catalog.'],
        ];
    }

    public function up(): void
    {
        $now = now();

        foreach ($this->baselinePermissions() as $permission) {
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
        $codes = array_column($this->baselinePermissions(), 'code');

        DB::table('security.permissions')->whereIn('code', $codes)->delete();
    }
};
