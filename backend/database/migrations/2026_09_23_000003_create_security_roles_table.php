<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * security.roles — named, programmatically addressed by `code` (stable identifier; application
 * code must branch on effective permissions, never on role code or name — see
 * App\Modules\Security\Domain\SecurityAdministrationCapability).
 *
 * is_system marks a role the bootstrap procedure or the platform itself depends on; S03 does not
 * forbid deactivating a system role outright, but the last-security-administrator invariant
 * (§17 of the S03 authorization) refuses any operation, on any role, that would leave zero ACTIVE
 * principals capable of security administration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('security.roles', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('code', 64);
            $table->string('name_ar');
            $table->string('name_en');
            $table->text('description')->nullable();
            $table->boolean('is_system')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('version')->default(1);
            $table->timestampTz('created_at');
            $table->timestampTz('updated_at');

            $table->unique('code', 'roles_code_unique');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE "security"."roles"
                ADD CONSTRAINT "roles_version_check"
                CHECK ("version" >= 1)
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('security.roles');
    }
};
