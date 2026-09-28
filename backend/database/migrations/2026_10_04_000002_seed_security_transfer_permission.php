<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Adds the one S14 permission row to the existing security.permissions catalog
 * (docs/transfer-foundation-specification.md §12), following the exact precedent of
 * 2026_10_02_000002_seed_security_full_secondment_period_permissions: a later stage's permissions
 * are added through a new migration, never by editing a released one. Default deny is unaffected —
 * no role is granted this permission by this migration. This single permission additionally
 * requires S08 organizational scope to cover up to three target units (destination, source
 * placement, source secondment) — that composition happens in TransferController via
 * ScopedAuthorizationChecker, not here.
 */
return new class extends Migration
{
    /** @return list<array{code: string, module: string, description: string}> */
    private function transferPermissions(): array
    {
        return [
            ['code' => 'hr.employment_relationships.transfer', 'module' => 'human_resources', 'description' => "Transfer an Employment Relationship's original organizational placement to a destination unit, closing an active full secondment as a consequence."],
        ];
    }

    public function up(): void
    {
        $now = now();

        foreach ($this->transferPermissions() as $permission) {
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
        $codes = array_column($this->transferPermissions(), 'code');

        DB::table('security.permissions')->whereIn('code', $codes)->delete();
    }
};
