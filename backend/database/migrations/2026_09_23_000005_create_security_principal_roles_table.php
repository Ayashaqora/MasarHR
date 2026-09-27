<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * security.principal_roles — role assignments. The UNIQUE constraint on (principal_id, role_id) is
 * the database-level guard against a duplicate-assignment race (two concurrent "assign this role"
 * requests can only ever result in one row).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('security.principal_roles', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('principal_id');
            $table->uuid('role_id');
            $table->timestampTz('assigned_at');
            $table->uuid('assigned_by')->nullable();

            $table->foreign('principal_id', 'principal_roles_principal_id_foreign')
                ->references('id')->on('security.principals');
            $table->foreign('role_id', 'principal_roles_role_id_foreign')
                ->references('id')->on('security.roles');
            $table->foreign('assigned_by', 'principal_roles_assigned_by_foreign')
                ->references('id')->on('security.principals');

            $table->unique(['principal_id', 'role_id'], 'principal_roles_principal_role_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('security.principal_roles');
    }
};
