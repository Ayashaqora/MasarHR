<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * security.role_permissions — which permissions a role grants. UNIQUE(role_id, permission_id)
 * guards duplicate-grant races the same way principal_roles guards duplicate role assignment.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('security.role_permissions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('role_id');
            $table->uuid('permission_id');
            $table->timestampTz('created_at');

            $table->foreign('role_id', 'role_permissions_role_id_foreign')
                ->references('id')->on('security.roles');
            $table->foreign('permission_id', 'role_permissions_permission_id_foreign')
                ->references('id')->on('security.permissions');

            $table->unique(['role_id', 'permission_id'], 'role_permissions_role_permission_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('security.role_permissions');
    }
};
