<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Adds the one S31 permission row (docs/movement-expiry-followup-foundation-specification.md
 * §S31.16), following the S20…S30 precedent: a later stage's permissions are added through a new
 * migration, never by editing a released one. Default deny is unaffected; the row is granted to no
 * role. Reading follow-ups is also organizational-scope filtered in the controller (S08). There is
 * no record/manage permission: follow-ups are written only by the system scanner, never by a user.
 */
return new class extends Migration
{
    /** @return list<array{code: string, module: string, description: string}> */
    private function movementExpiryFollowUpPermissions(): array
    {
        return [
            ['code' => 'hr.movement_expiry_followups.view', 'module' => 'human_resources', 'description' => 'View movement expiry follow-ups within the organizational scope.'],
        ];
    }

    public function up(): void
    {
        $now = now();

        foreach ($this->movementExpiryFollowUpPermissions() as $permission) {
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
        $codes = array_column($this->movementExpiryFollowUpPermissions(), 'code');

        DB::table('security.permissions')->whereIn('code', $codes)->delete();
    }
};
