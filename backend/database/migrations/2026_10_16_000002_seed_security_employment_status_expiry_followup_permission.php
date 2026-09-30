<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Adds the one S38 permission row (docs/employment-status-expiry-followup-specification.md §S38.15),
 * following the S31 precedent: a later stage's permissions are added through a new migration, never by
 * editing a released one. Default deny is unaffected; the row is granted to no role. The permission is
 * DEDICATED — hr.movement_expiry_followups.view does not grant it. The read API is plain RBAC (no
 * organizational scope: a status period has no organizational unit). There is no record/manage
 * permission: follow-ups are written only by the system scanner, never by a user.
 */
return new class extends Migration
{
    /** @return list<array{code: string, module: string, description: string}> */
    private function employmentStatusExpiryFollowUpPermissions(): array
    {
        return [
            ['code' => 'hr.employment_status_expiry_followups.view', 'module' => 'human_resources', 'description' => 'View employment status expiry follow-ups.'],
        ];
    }

    public function up(): void
    {
        $now = now();

        foreach ($this->employmentStatusExpiryFollowUpPermissions() as $permission) {
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
        DB::table('security.permissions')->whereIn('code', array_column($this->employmentStatusExpiryFollowUpPermissions(), 'code'))->delete();
    }
};
