<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * security.principals — the security/authentication identity (S03).
 *
 * A Principal is NOT an HR employee. It carries no national_id and no employment data; it exists
 * only to authenticate and to be authorized. Future stages may link a Principal to an Employee,
 * but that linkage is owned by whichever stage introduces it, not by S03.
 *
 * username_normalized is the canonical, case-insensitive identity used for lookup and uniqueness
 * (see App\Modules\Security\Domain\UsernameNormalizer, the single implementation of that rule) so
 * "Admin" and "admin" can never become two principals. username stores the value as entered/displayed.
 *
 * version is the optimistic-concurrency counter for ChangePrincipalUsername/DisplayName/Status.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('security.principals', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('username')->comment('Display form of the login identity, as entered.');
            $table->string('username_normalized')->comment('Canonical lowercase/trimmed form; unique identity.');
            $table->string('display_name');
            $table->string('status', 16)->default('ACTIVE');
            $table->unsignedInteger('version')->default(1);
            $table->timestampTz('created_at');
            $table->timestampTz('updated_at');

            $table->unique('username_normalized', 'principals_username_normalized_unique');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE "security"."principals"
                ADD CONSTRAINT "principals_status_check"
                CHECK ("status" IN ('ACTIVE', 'DISABLED'))
            SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE "security"."principals"
                ADD CONSTRAINT "principals_version_check"
                CHECK ("version" >= 1)
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('security.principals');
    }
};
