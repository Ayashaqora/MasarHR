<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * PostgreSQL-level immutability for audit.audit_entries (S04 §12/D6). Defense in depth: the
 * application already never issues UPDATE/DELETE against this table, but this trigger rejects
 * both directly at the database, regardless of how the statement was issued. SQLSTATE 'MA001' is a
 * deliberately non-standard, application-owned code (see
 * PostgresErrorClassifier::isAuditImmutabilityViolation()) so it can never be confused with a
 * built-in PostgreSQL error class.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE FUNCTION audit.reject_audit_mutation() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'audit.audit_entries is append-only; % is not permitted', TG_OP
                    USING ERRCODE = 'MA001';
            END;
            $$ LANGUAGE plpgsql;
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER audit_entries_immutable
                BEFORE UPDATE OR DELETE ON audit.audit_entries
                FOR EACH ROW EXECUTE FUNCTION audit.reject_audit_mutation();
            SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS audit_entries_immutable ON audit.audit_entries');
        DB::unprepared('DROP FUNCTION IF EXISTS audit.reject_audit_mutation()');
    }
};
