<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Adds the single S08 permission to the existing security.permissions catalog (spec §18). Owned by
 * the Security module itself (module = 'security'), not by Organization — this permission gates
 * administration of a new Security authorization dimension, not the org hierarchy. Mirrors the
 * exact precedent of 2026_09_27_000002_seed_security_organization_permissions and, before that,
 * 2026_09_23_000007_seed_security_baseline_permissions: a later stage's permission is added through
 * its own new migration, never by editing an already-released one. Default deny is unaffected: no
 * existing role is granted this permission by this migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('security.permissions')->insert([
            'id' => (string) Str::uuid7(),
            'code' => 'security.organization_scopes.manage',
            'module' => 'security',
            'description' => 'Grant or revoke a principal\'s organizational access scope (global or unit-subtree).',
            'created_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('security.permissions')->where('code', 'security.organization_scopes.manage')->delete();
    }
};
