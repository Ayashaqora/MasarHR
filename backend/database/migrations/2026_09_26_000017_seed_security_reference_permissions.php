<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Adds the two S05 Reference-module permission rows to the existing security.permissions catalog
 * (S05 §16). Per the exact S03 precedent (2026_09_23_000007_seed_security_baseline_permissions),
 * a later stage's permissions are added through a new migration; this migration never edits that
 * S03 migration or any other released migration.
 */
return new class extends Migration
{
    /** @return list<array{code: string, module: string, description: string}> */
    private function referencePermissions(): array
    {
        return [
            ['code' => 'reference.view', 'module' => 'reference', 'description' => 'View reference data values (Gender, Marital Status, Employment Status, Decision Type, etc.).'],
            ['code' => 'reference.manage', 'module' => 'reference', 'description' => 'Create, update, activate, deactivate reference data values and define employment status behavior periods.'],
        ];
    }

    public function up(): void
    {
        $now = now();

        foreach ($this->referencePermissions() as $permission) {
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
        $codes = array_column($this->referencePermissions(), 'code');

        DB::table('security.permissions')->whereIn('code', $codes)->delete();
    }
};
