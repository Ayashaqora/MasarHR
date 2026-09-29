<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Adds the two S30 permission rows (docs/partial-secondment-foundation-specification.md §S30.15),
 * following the S20/S21/S22/S26/S29 precedent: a later stage's permissions are added through a new
 * migration, never by editing a released one. Default deny is unaffected; the rows are granted to
 * no role. Organizational scope is enforced by the controller (S08), exactly as for S12/S16.
 */
return new class extends Migration
{
    /** @return list<array{code: string, module: string, description: string}> */
    private function partialSecondmentPeriodPermissions(): array
    {
        return [
            ['code' => 'hr.partial_secondment_periods.view', 'module' => 'human_resources', 'description' => "List an Employment Relationship's partial secondment history."],
            ['code' => 'hr.partial_secondment_periods.record', 'module' => 'human_resources', 'description' => 'Record a partial secondment period for an Employment Relationship.'],
        ];
    }

    public function up(): void
    {
        $now = now();

        foreach ($this->partialSecondmentPeriodPermissions() as $permission) {
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
        $codes = array_column($this->partialSecondmentPeriodPermissions(), 'code');

        DB::table('security.permissions')->whereIn('code', $codes)->delete();
    }
};
