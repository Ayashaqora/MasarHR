<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * security.permissions — the fixed catalog of grantable capabilities. S03 owns only permissions of
 * the Security module (see the following data migration for the baseline set). A later stage adds
 * its own permission rows through its own migration; S03 never pre-creates speculative permissions
 * for employees/organization/reporting/etc.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('security.permissions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('code', 128);
            $table->string('module', 64);
            $table->text('description')->nullable();
            $table->timestampTz('created_at');

            $table->unique('code', 'permissions_code_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('security.permissions');
    }
};
