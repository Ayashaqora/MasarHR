<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Adds the two S23 permission rows to the existing security.permissions catalog
 * (docs/person-qualification-foundation-specification.md §S23.12), following the exact precedent of
 * the S20–S22 permission seeds. Default deny is unaffected.
 *
 * hr.* permissions over the Person qualification fact — deliberately distinct from the reference.*
 * permissions that administer ref.academic_degrees / ref.qualification_types. Plain RBAC only, the
 * same Person-level precedent as S09's hr.persons.* permissions (ADR-S23-001 §9).
 */
return new class extends Migration
{
    /** @return list<array{code: string, module: string, description: string}> */
    private function personQualificationPermissions(): array
    {
        return [
            ['code' => 'hr.person_qualifications.view', 'module' => 'human_resources', 'description' => "List a Person's recorded qualifications."],
            ['code' => 'hr.person_qualifications.record', 'module' => 'human_resources', 'description' => 'Record a qualification fact for a Person.'],
        ];
    }

    public function up(): void
    {
        $now = now();

        foreach ($this->personQualificationPermissions() as $permission) {
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
        $codes = array_column($this->personQualificationPermissions(), 'code');

        DB::table('security.permissions')->whereIn('code', $codes)->delete();
    }
};
