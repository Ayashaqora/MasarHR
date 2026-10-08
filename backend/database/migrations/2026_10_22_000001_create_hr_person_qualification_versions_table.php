<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * S48 SCHEMA-01 (docs/person-qualification-history-foundation-specification.md §S48.3/§S48.8,
 * ADR-S48-DECISIONS D10/D14/D17/D22/D23/D24/D32/D33): the versioned logical record for a Person
 * Qualification. `hr.person_qualifications` keeps its stable identity (`id`, `person_id`,
 * `is_primary`, `created_at`); this migration adds the append-only-in-effect
 * `hr.person_qualification_versions` table (the only permitted UPDATE is the single
 * `is_current: true -> false` flip; DELETE is never permitted) and the `hr.person_qualifications_current`
 * view the whole application reads through going forward (§S48.13).
 *
 * This migration step 2 of §S48.18's cutover plan: it creates the new table, its constraints and
 * triggers, and the view, while the old `academic_degree_id`/`qualification_type_id` columns and
 * every existing consumer are left untouched — nothing here drops anything or writes to the parent
 * table. The version-1 backfill is a separate migration (step 3); dropping the old columns is a
 * separate migration again (step 5), run only after every consumer in §S48.13 is repointed.
 *
 * Deferred guarantees fire at COMMIT, not before — "at most one current" is an ordinary immediate
 * partial unique index (D32: it never needed deferring, because the correction sequence never
 * creates two current rows at once, only a momentary zero); "at least one current" has no
 * declarative PostgreSQL equivalent at all, so it is a DEFERRABLE INITIALLY DEFERRED constraint
 * trigger, covering both this table's own INSERT/UPDATE/DELETE and the PARENT table's INSERT (a
 * qualification can never exist with zero versions, D32).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr.person_qualification_versions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('person_qualification_id');
            $table->uuid('person_id');
            $table->integer('version_number');
            $table->uuid('academic_degree_id')->nullable();
            $table->uuid('qualification_type_id')->nullable();
            $table->date('obtained_on')->nullable();
            $table->boolean('is_current')->default(true);
            $table->text('reason')->nullable();
            $table->uuid('created_by_principal_id')->nullable();
            $table->timestampTz('created_at');

            $table->foreign('person_qualification_id', 'person_qualification_versions_qualification_fk')
                ->references('id')->on('hr.person_qualifications')->restrictOnDelete();
            $table->foreign('person_id', 'person_qualification_versions_person_fk')
                ->references('id')->on('hr.persons')->restrictOnDelete();
            $table->foreign('academic_degree_id', 'person_qualification_versions_academic_degree_fk')
                ->references('id')->on('ref.academic_degrees')->restrictOnDelete();
            $table->foreign('qualification_type_id', 'person_qualification_versions_qualification_type_fk')
                ->references('id')->on('ref.qualification_types')->restrictOnDelete();
            $table->foreign('created_by_principal_id', 'person_qualification_versions_created_by_fk')
                ->references('id')->on('security.principals')->restrictOnDelete();

            $table->index('person_qualification_id', 'person_qualification_versions_qualification_id_index');
            $table->index('person_id', 'person_qualification_versions_person_id_index');
            $table->index('academic_degree_id', 'person_qualification_versions_academic_degree_id_index');
            $table->index('qualification_type_id', 'person_qualification_versions_qualification_type_id_index');
            $table->index('created_by_principal_id', 'person_qualification_versions_created_by_index');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE "hr"."person_qualification_versions"
                ADD CONSTRAINT "person_qualification_versions_version_number_check"
                CHECK ("version_number" >= 1)
            SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE "hr"."person_qualification_versions"
                ADD CONSTRAINT "person_qualification_versions_number_unique"
                UNIQUE ("person_qualification_id", "version_number")
            SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE "hr"."person_qualification_versions"
                ADD CONSTRAINT "person_qualification_versions_identity_present_check"
                CHECK ("academic_degree_id" IS NOT NULL OR "qualification_type_id" IS NOT NULL)
            SQL);

        // "At most one current version" (D32): ordinary, immediate, non-deferred — the
        // UPDATE-then-INSERT correction sequence never produces two current rows at once, only a
        // momentary zero, so this half never needed a deferred mechanism (§S48.8).
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX "person_qualification_versions_one_current" ON
            "hr"."person_qualification_versions" ("person_qualification_id") WHERE "is_current"
            SQL);

        // Current-identity duplicate prevention, NULL-safe, scoped WITHIN one Person by
        // construction — "person_id" is one of the indexed columns (D14/D23/D40, §S48.8).
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX "person_qualification_versions_current_identity_unique" ON
            "hr"."person_qualification_versions" ("person_id", "academic_degree_id", "qualification_type_id")
            NULLS NOT DISTINCT WHERE "is_current"
            SQL);

        // A version's person_id matches its qualification's owner, on INSERT and UPDATE (D24, MA002).
        DB::unprepared(<<<'SQL'
            CREATE FUNCTION hr.enforce_qualification_version_person_match() RETURNS trigger AS $$
            DECLARE
                expected_person_id uuid;
            BEGIN
                SELECT person_id INTO expected_person_id
                FROM hr.person_qualifications WHERE id = NEW.person_qualification_id;

                IF expected_person_id IS NULL OR NEW.person_id IS DISTINCT FROM expected_person_id THEN
                    RAISE EXCEPTION 'hr.person_qualification_versions.person_id must match the owning qualification''s person_id'
                        USING ERRCODE = 'MA002';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER person_qualification_versions_person_match
                BEFORE INSERT OR UPDATE ON hr.person_qualification_versions
                FOR EACH ROW EXECUTE FUNCTION hr.enforce_qualification_version_person_match();
            SQL);

        // Historical versions are immutable except the single is_current: true -> false
        // transition, and can never be deleted (D24, MA003/MA004).
        DB::unprepared(<<<'SQL'
            CREATE FUNCTION hr.enforce_qualification_version_immutability() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'hr.person_qualification_versions rows can never be deleted'
                        USING ERRCODE = 'MA004';
                END IF;

                IF NEW.id IS DISTINCT FROM OLD.id
                    OR NEW.person_qualification_id IS DISTINCT FROM OLD.person_qualification_id
                    OR NEW.person_id IS DISTINCT FROM OLD.person_id
                    OR NEW.version_number IS DISTINCT FROM OLD.version_number
                    OR NEW.academic_degree_id IS DISTINCT FROM OLD.academic_degree_id
                    OR NEW.qualification_type_id IS DISTINCT FROM OLD.qualification_type_id
                    OR NEW.obtained_on IS DISTINCT FROM OLD.obtained_on
                    OR NEW.reason IS DISTINCT FROM OLD.reason
                    OR NEW.created_by_principal_id IS DISTINCT FROM OLD.created_by_principal_id
                    OR NEW.created_at IS DISTINCT FROM OLD.created_at
                    OR OLD.is_current IS NOT TRUE
                    OR NEW.is_current IS NOT FALSE
                THEN
                    RAISE EXCEPTION 'hr.person_qualification_versions rows are immutable except the single is_current: true -> false transition'
                        USING ERRCODE = 'MA003';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER person_qualification_versions_immutable
                BEFORE UPDATE OR DELETE ON hr.person_qualification_versions
                FOR EACH ROW EXECUTE FUNCTION hr.enforce_qualification_version_immutability();
            SQL);

        // "At least one current version", deferred to COMMIT — the one half PostgreSQL has no
        // declarative way to express, now covering BOTH this table's own writes and the PARENT
        // table's INSERT (D32, MA005). Verbatim per §S48.8.
        DB::unprepared(<<<'SQL'
            CREATE FUNCTION hr.check_qualification_has_current_version() RETURNS trigger AS $$
            DECLARE
                affected_id uuid;
            BEGIN
                affected_id := COALESCE(NEW.person_qualification_id, OLD.person_qualification_id);
                IF NOT EXISTS (
                    SELECT 1 FROM hr.person_qualification_versions
                    WHERE person_qualification_id = affected_id AND is_current
                ) THEN
                    RAISE EXCEPTION 'qualification % has no current version at commit', affected_id
                        USING ERRCODE = 'MA005';
                END IF;
                RETURN NULL;
            END;
            $$ LANGUAGE plpgsql;
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE CONSTRAINT TRIGGER person_qualification_versions_at_least_one_current
                AFTER INSERT OR UPDATE OR DELETE ON hr.person_qualification_versions
                DEFERRABLE INITIALLY DEFERRED
                FOR EACH ROW EXECUTE FUNCTION hr.check_qualification_has_current_version();
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE FUNCTION hr.check_new_qualification_has_current_version() RETURNS trigger AS $$
            BEGIN
                IF NOT EXISTS (
                    SELECT 1 FROM hr.person_qualification_versions
                    WHERE person_qualification_id = NEW.id AND is_current
                ) THEN
                    RAISE EXCEPTION 'qualification % has no current version at commit', NEW.id
                        USING ERRCODE = 'MA005';
                END IF;
                RETURN NULL;
            END;
            $$ LANGUAGE plpgsql;
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE CONSTRAINT TRIGGER person_qualifications_has_current_version
                AFTER INSERT ON hr.person_qualifications
                DEFERRABLE INITIALLY DEFERRED
                FOR EACH ROW EXECUTE FUNCTION hr.check_new_qualification_has_current_version();
            SQL);

        // New in RC4 (D33): the parent qualification row's own id/person_id become immutable once
        // created — closes the gap the versions-table person-match trigger alone could not (MA006).
        DB::unprepared(<<<'SQL'
            CREATE FUNCTION hr.enforce_person_qualification_identity_immutable() RETURNS trigger AS $$
            BEGIN
                IF NEW.id IS DISTINCT FROM OLD.id OR NEW.person_id IS DISTINCT FROM OLD.person_id THEN
                    RAISE EXCEPTION 'hr.person_qualifications.id and person_id are immutable once created'
                        USING ERRCODE = 'MA006';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER person_qualifications_identity_immutable
                BEFORE UPDATE ON hr.person_qualifications
                FOR EACH ROW EXECUTE FUNCTION hr.enforce_person_qualification_identity_immutable();
            SQL);

        // hr.person_qualifications_current — the one read path the whole application repoints to
        // (§S48.3/§S48.13), verbatim.
        DB::unprepared(<<<'SQL'
            CREATE VIEW hr.person_qualifications_current AS
            SELECT pq.id, pq.person_id, pq.is_primary, pq.created_at,
                   v.academic_degree_id, v.qualification_type_id, v.obtained_on, v.version_number
            FROM hr.person_qualifications pq
            JOIN hr.person_qualification_versions v
              ON v.person_qualification_id = pq.id AND v.is_current
            SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP VIEW IF EXISTS hr.person_qualifications_current');
        DB::unprepared('DROP TRIGGER IF EXISTS person_qualifications_identity_immutable ON hr.person_qualifications');
        DB::unprepared('DROP FUNCTION IF EXISTS hr.enforce_person_qualification_identity_immutable()');
        DB::unprepared('DROP TRIGGER IF EXISTS person_qualifications_has_current_version ON hr.person_qualifications');
        DB::unprepared('DROP FUNCTION IF EXISTS hr.check_new_qualification_has_current_version()');
        DB::unprepared('DROP TRIGGER IF EXISTS person_qualification_versions_at_least_one_current ON hr.person_qualification_versions');
        DB::unprepared('DROP FUNCTION IF EXISTS hr.check_qualification_has_current_version()');
        DB::unprepared('DROP TRIGGER IF EXISTS person_qualification_versions_immutable ON hr.person_qualification_versions');
        DB::unprepared('DROP FUNCTION IF EXISTS hr.enforce_qualification_version_immutability()');
        DB::unprepared('DROP TRIGGER IF EXISTS person_qualification_versions_person_match ON hr.person_qualification_versions');
        DB::unprepared('DROP FUNCTION IF EXISTS hr.enforce_qualification_version_person_match()');
        Schema::dropIfExists('hr.person_qualification_versions');
    }
};
