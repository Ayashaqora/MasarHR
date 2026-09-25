<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * audit.audit_entries — the S04 audit trail (see
 * docs/audit-command-infrastructure-specification.md §10/§11). Append-only: no UPDATE/DELETE path
 * exists at the application level (AuditAppendService is insert-only), and the next migration adds
 * a PostgreSQL trigger that rejects both directly regardless of how the statement was issued
 * (§12/D6).
 *
 * actor_principal_id references security.principals(id) ON DELETE RESTRICT — safe because S03
 * principals are never hard-deleted (see docs/security-access-foundation.md §3); if that ever
 * changes, this FK fails loudly against any principal with audit history rather than silently
 * orphaning it (§11's foreign-key analysis).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit.audit_entries', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->timestampTz('occurred_at');
            $table->string('category', 32)->comment('MUTATION | SECURITY_EVENT');
            $table->string('action')->comment('Stable dotted action code — never a PHP class/method name.');
            $table->string('actor_type', 16)->comment('HUMAN | SYSTEM');
            $table->uuid('actor_principal_id')->nullable();
            $table->string('actor_label')->nullable()->comment('Controlled, application-chosen value — required iff SYSTEM.');
            $table->string('source', 16)->comment('HTTP | CLI | SYSTEM');
            $table->uuid('correlation_id')->comment('Tracing metadata only — never authorization evidence (AUD-09).');
            $table->string('target_type');
            $table->string('target_id')->nullable();
            $table->string('outcome', 16)->comment('SUCCEEDED | REJECTED');
            $table->jsonb('changes')->nullable()->comment('Minimum semantic delta only — never a full entity snapshot.');
            $table->jsonb('metadata')->nullable()->comment('Allowlisted supplementary context only.');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE "audit"."audit_entries"
                ADD CONSTRAINT "audit_entries_category_check"
                CHECK ("category" IN ('MUTATION', 'SECURITY_EVENT'))
            SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE "audit"."audit_entries"
                ADD CONSTRAINT "audit_entries_actor_type_check"
                CHECK ("actor_type" IN ('HUMAN', 'SYSTEM'))
            SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE "audit"."audit_entries"
                ADD CONSTRAINT "audit_entries_source_check"
                CHECK ("source" IN ('HTTP', 'CLI', 'SYSTEM'))
            SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE "audit"."audit_entries"
                ADD CONSTRAINT "audit_entries_outcome_check"
                CHECK ("outcome" IN ('SUCCEEDED', 'REJECTED'))
            SQL);

        // AUD-05/AUD-06: HUMAN requires a Principal id and no label; SYSTEM requires a label and
        // no Principal id.
        DB::statement(<<<'SQL'
            ALTER TABLE "audit"."audit_entries"
                ADD CONSTRAINT "audit_entries_actor_check"
                CHECK (
                    ("actor_type" = 'HUMAN'  AND "actor_principal_id" IS NOT NULL AND "actor_label" IS NULL) OR
                    ("actor_type" = 'SYSTEM' AND "actor_principal_id" IS NULL     AND "actor_label" IS NOT NULL)
                )
            SQL);

        // §11 foreign-key analysis: RESTRICT, not CASCADE — guards a future stage that might
        // introduce principal hard-deletion; no such deletion exists under S03 today.
        DB::statement(<<<'SQL'
            ALTER TABLE "audit"."audit_entries"
                ADD CONSTRAINT "audit_entries_actor_principal_id_foreign"
                FOREIGN KEY ("actor_principal_id") REFERENCES "security"."principals" ("id")
                ON DELETE RESTRICT
            SQL);

        Schema::table('audit.audit_entries', function (Blueprint $table): void {
            $table->index('occurred_at', 'audit_entries_occurred_at_idx');
            $table->index(['action', 'occurred_at'], 'audit_entries_action_occurred_idx');
            $table->index(['target_type', 'target_id', 'occurred_at'], 'audit_entries_target_idx');
            $table->index('correlation_id', 'audit_entries_correlation_id_idx');
        });

        // Partial index (§11/§21/Q15) — actor_principal_id is NULL for every SYSTEM row.
        DB::statement(<<<'SQL'
            CREATE INDEX "audit_entries_actor_principal_occurred_idx"
                ON "audit"."audit_entries" ("actor_principal_id", "occurred_at")
                WHERE "actor_principal_id" IS NOT NULL
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('audit.audit_entries');
    }
};
