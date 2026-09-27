<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * security.organizational_scope_grants — S08's sole table
 * (docs/organizational-access-scope-specification.md §10). A grant/assignment row, the same
 * category as security.principal_roles/security.role_permissions (no `version` column, real DELETE
 * on revoke — spec §14), not a versioned business entity like org.organizational_units.
 *
 * Exactly two scope kinds, GLOBAL and UNIT (spec §7). GLOBAL is never encoded as NULL-ambiguity or a
 * magic unit id: the kind/unit pairing is enforced by a CHECK constraint, and duplicate concurrent
 * grants are prevented by two partial unique indexes (spec §10) rather than a single plain UNIQUE,
 * because PostgreSQL treats multiple NULLs in an ordinary UNIQUE constraint as distinct — a plain
 * UNIQUE(principal_id, scope_kind, organizational_unit_id) would not stop a principal from
 * accumulating more than one GLOBAL row.
 *
 * organizational_unit_id is a cross-schema FK to org.organizational_units(id) — the same shape
 * audit.audit_entries.actor_principal_id already uses to reference security.principals(id), so this
 * is not a new pattern. ON DELETE RESTRICT because org.organizational_units never hard-deletes a
 * row today; RESTRICT fails loudly rather than silently orphaning a grant if that ever changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('security.organizational_scope_grants', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('principal_id');
            $table->string('scope_kind', 16);
            $table->uuid('organizational_unit_id')->nullable();
            $table->timestampTz('granted_at');
            $table->uuid('granted_by')->nullable();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE "security"."organizational_scope_grants"
                ADD CONSTRAINT "organizational_scope_grants_principal_id_foreign"
                FOREIGN KEY ("principal_id") REFERENCES "security"."principals" ("id")
            SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE "security"."organizational_scope_grants"
                ADD CONSTRAINT "organizational_scope_grants_granted_by_foreign"
                FOREIGN KEY ("granted_by") REFERENCES "security"."principals" ("id")
            SQL);

        // RESTRICT, not CASCADE — mirrors audit_entries_actor_principal_id_foreign's own reasoning:
        // org.organizational_units performs no hard-delete today, but a grant must fail loudly
        // rather than silently vanish if that ever changes.
        DB::statement(<<<'SQL'
            ALTER TABLE "security"."organizational_scope_grants"
                ADD CONSTRAINT "organizational_scope_grants_unit_id_foreign"
                FOREIGN KEY ("organizational_unit_id") REFERENCES "org"."organizational_units" ("id")
                ON DELETE RESTRICT
            SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE "security"."organizational_scope_grants"
                ADD CONSTRAINT "organizational_scope_grants_kind_check"
                CHECK ("scope_kind" IN ('GLOBAL', 'UNIT'))
            SQL);

        // Spec §7: GLOBAL <-> no unit; UNIT <-> a real unit. Both directions enforced here, not
        // only in application code.
        DB::statement(<<<'SQL'
            ALTER TABLE "security"."organizational_scope_grants"
                ADD CONSTRAINT "organizational_scope_grants_kind_unit_pairing_check"
                CHECK (
                    ("scope_kind" = 'UNIT'   AND "organizational_unit_id" IS NOT NULL) OR
                    ("scope_kind" = 'GLOBAL' AND "organizational_unit_id" IS NULL)
                )
            SQL);

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX "organizational_scope_grants_unique_global"
                ON "security"."organizational_scope_grants" ("principal_id")
                WHERE "scope_kind" = 'GLOBAL'
            SQL);

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX "organizational_scope_grants_unique_unit"
                ON "security"."organizational_scope_grants" ("principal_id", "organizational_unit_id")
                WHERE "scope_kind" = 'UNIT'
            SQL);

        Schema::table('security.organizational_scope_grants', function (Blueprint $table): void {
            $table->index('organizational_unit_id', 'organizational_scope_grants_unit_id_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('security.organizational_scope_grants');
    }
};
