<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * S24 Person Profile Foundation (docs/person-profile-foundation-specification.md §S24.5,
 * ADR-S24-001): extends the existing S09 Person aggregate — never an Employment Relationship, and
 * never a separate person_profile table — with its current demographic attributes.
 *
 * Every column is NULLABLE at the database level on purpose (ADR-S24-001 §5/§7): pre-S24 Persons
 * carry none of these values and must remain valid, readable, un-backfilled rows ("explicit
 * incomplete legacy data"). "Required for a NEW Person" (full_name_ar, gender_id,
 * marital_status_id, birth_date) is an application/domain creation invariant enforced by
 * CreatePerson, not a destructive NOT NULL constraint that would force fake legacy values.
 *
 * Current values only — no demographic history table (changes are captured by the immutable S04
 * audit trail). No name parts, no English name, no age column (age is always derived from
 * birth_date at report/as-of time), no birth-place catalog, no start-work date, no
 * knowledge-state column (NULL means unknown for legacy rows). References are RESTRICT, like every
 * other ref.* consumer, so a referenced gender/marital status can be deactivated but never
 * hard-deleted out from under a Person.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr.persons', function (Blueprint $table): void {
            $table->string('full_name_ar', 255)->nullable();
            $table->uuid('gender_id')->nullable();
            $table->uuid('marital_status_id')->nullable();
            $table->date('birth_date')->nullable();
            $table->string('birth_place', 255)->nullable();

            $table->foreign('gender_id', 'persons_gender_fk')
                ->references('id')->on('ref.genders')->restrictOnDelete();
            $table->foreign('marital_status_id', 'persons_marital_status_fk')
                ->references('id')->on('ref.marital_statuses')->restrictOnDelete();
            $table->index('gender_id', 'persons_gender_id_index');
            $table->index('marital_status_id', 'persons_marital_status_id_index');
        });

        // A stored name/birth place is never blank — the "unknown" legacy state is NULL, not ''.
        DB::statement(<<<'SQL'
            ALTER TABLE "hr"."persons"
                ADD CONSTRAINT "persons_full_name_ar_not_blank_check"
                CHECK ("full_name_ar" IS NULL OR btrim("full_name_ar") <> '')
            SQL);
        DB::statement(<<<'SQL'
            ALTER TABLE "hr"."persons"
                ADD CONSTRAINT "persons_birth_place_not_blank_check"
                CHECK ("birth_place" IS NULL OR btrim("birth_place") <> '')
            SQL);
    }

    public function down(): void
    {
        Schema::table('hr.persons', function (Blueprint $table): void {
            $table->dropForeign('persons_gender_fk');
            $table->dropForeign('persons_marital_status_fk');
        });

        // Schema-qualified: PostgreSQL resolves a bare index name through search_path, which does
        // not include the hr schema.
        DB::statement('DROP INDEX "hr"."persons_gender_id_index"');
        DB::statement('DROP INDEX "hr"."persons_marital_status_id_index"');

        DB::statement('ALTER TABLE "hr"."persons" DROP CONSTRAINT IF EXISTS "persons_full_name_ar_not_blank_check"');
        DB::statement('ALTER TABLE "hr"."persons" DROP CONSTRAINT IF EXISTS "persons_birth_place_not_blank_check"');

        Schema::table('hr.persons', function (Blueprint $table): void {
            $table->dropColumn(['full_name_ar', 'gender_id', 'marital_status_id', 'birth_date', 'birth_place']);
        });
    }
};
