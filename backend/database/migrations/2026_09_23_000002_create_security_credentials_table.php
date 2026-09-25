<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * security.credentials — one row per (principal, credential_type). S03 defines only PASSWORD.
 *
 * password_hash is produced by Laravel's Hash facade (bcrypt); this table never stores plaintext
 * and is never selected into an API resource, log line or exception message (see
 * App\Modules\Security\Infrastructure\Persistence\Eloquent\Credential, which hides password_hash).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('security.credentials', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('principal_id');
            $table->string('credential_type', 32);
            $table->string('password_hash');
            $table->timestampTz('password_changed_at');
            $table->timestampTz('created_at');
            $table->timestampTz('updated_at');

            $table->foreign('principal_id', 'credentials_principal_id_foreign')
                ->references('id')->on('security.principals');

            $table->unique(['principal_id', 'credential_type'], 'credentials_principal_type_unique');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE "security"."credentials"
                ADD CONSTRAINT "credentials_type_check"
                CHECK ("credential_type" IN ('PASSWORD'))
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('security.credentials');
    }
};
