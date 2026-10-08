<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * S48: the one permission this stage adds
 * (docs/person-qualification-history-foundation-specification.md §S48.14, D07). Independent of
 * record/view/designate_primary — plain RBAC, granted to no role by this seed, same convention as
 * 2026_10_18_000003_seed_security_human_cadre_permissions.
 */
return new class extends Migration
{
    private function permissions(): array
    {
        return [
            ['code' => 'hr.person_qualifications.correct', 'module' => 'human_resources', 'description' => "Correct a previously recorded Person qualification's degree, type, or date obtained, keeping its full version history."],
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
        // S48 rollback gate (§S48.18, corrected gate D42, precision corrected D44) — the SAME
        // check (byte-for-byte) as 2026_10_22_000002's and 2026_10_22_000004's own down(), each
        // repeated independently rather than shared (anonymous classes, no autoloaded common
        // base, composer.json out of scope for this change — see 000002's down() for the full
        // rationale). Rollback runs most-recent-migration-first, so THIS file's down() is the
        // first to execute in the S48 rollback chain; it must refuse before touching
        // security.permissions, exactly as 000004 must refuse before touching the schema and
        // 000002 before touching version data.
        $liveWriteExists = DB::selectOne(<<<'SQL'
            SELECT EXISTS (
                SELECT 1 FROM hr.person_qualification_versions WHERE created_by_principal_id IS NOT NULL
            ) AS exists_flag
            SQL)->exists_flag;

        if ($liveWriteExists) {
            throw new RuntimeException(
                'S48 rollback refused: at least one hr.person_qualification_versions row has a '.
                'known created_by_principal_id (from the backfill resolving a real actor, or from '.
                'a genuine post-migration write). Reverting would silently destroy real '.
                'obtained_on/actor data. Refused before any schema, permission, version-data, or '.
                'migrations-record change — no partial reversal is performed.'
            );
        }

        DB::table('security.permissions')->whereIn('code', array_column($this->permissions(), 'code'))->delete();
    }
};
