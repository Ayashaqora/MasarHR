<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Adds the one S24 permission row (docs/person-profile-foundation-specification.md §S24.12),
 * following the S14 single-permission precedent (seed_security_transfer_permission). Named on the
 * existing hr.persons.* family, like S14's hr.employment_relationships.transfer: updating a
 * profile is an action on the Person aggregate itself. Reads keep using the existing
 * hr.persons.view (no redundant view permission), and creation keeps using hr.persons.create.
 * Default deny is unaffected.
 */
return new class extends Migration
{
    private const CODE = 'hr.persons.update_profile';

    public function up(): void
    {
        DB::table('security.permissions')->insert([
            'id' => (string) Str::uuid7(),
            'code' => self::CODE,
            'module' => 'human_resources',
            'description' => "Update a Person's profile (full Arabic name, gender, marital status, birth date, birth place).",
            'created_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('security.permissions')->where('code', self::CODE)->delete();
    }
};
