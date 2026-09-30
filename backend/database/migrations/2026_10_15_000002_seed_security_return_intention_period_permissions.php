<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * S34 permissions (independent of the employment-status permissions), added through a new migration
 * per the S10/S29 precedent. Default deny is unaffected — no role is granted either of these.
 */
return new class extends Migration
{
    /** @return list<array{code: string, module: string, description: string}> */
    private function permissions(): array
    {
        return [
            ['code' => 'hr.return_intention_periods.view', 'module' => 'human_resources', 'description' => "List an Employment Relationship's Return Intention history and effective value."],
            ['code' => 'hr.return_intention_periods.record', 'module' => 'human_resources', 'description' => 'Record a Return Intention period for an Employment Relationship.'],
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
