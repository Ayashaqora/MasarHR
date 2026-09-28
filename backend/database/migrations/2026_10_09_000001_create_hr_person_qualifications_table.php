<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * hr.person_qualifications — S23's Person Qualification Foundation
 * (docs/person-qualification-foundation-specification.md §S23.6, ADR-S23-001): a child of the S09
 * Person aggregate — never of an Employment Relationship — recording one attained qualification
 * fact. Person 1 → 0..* qualifications (ADR-S23-DECISIONS §1/§2): the legacy single-value snapshot
 * does not limit the domain to one.
 *
 * Identity (ADR-S23-DECISIONS §5): an OPTIONAL academic degree (ref.academic_degrees) and an
 * OPTIONAL qualification type (ref.qualification_types) — two independent dimensions, not a
 * hierarchy, with at least one present (CHECK). Duplicate protection is the exact identity per
 * person, with NULL treated as a value (UNIQUE NULLS NOT DISTINCT), so the same fact cannot be
 * recorded twice while different degree/type combinations remain legitimate.
 *
 * Deliberately absent (ADR-S23-DECISIONS §3/§4/§7/§8): no date of any kind and no knowledge-state
 * marker (no source-supported date exists), no specialty (academic specialty is out of S23;
 * ref.specialties keeps its professional/cadre semantics), no primary/highest/current flag, no
 * institution/country/grade/certificate/verification fields, no employment_relationship_id, and no
 * `version`/`updated_at` (a recorded fact is never edited in S23 — correction is deferred).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr.person_qualifications', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('person_id');
            $table->uuid('academic_degree_id')->nullable();
            $table->uuid('qualification_type_id')->nullable();
            $table->timestampTz('created_at');

            $table->foreign('person_id', 'person_qualifications_person_fk')
                ->references('id')->on('hr.persons')->restrictOnDelete();
            $table->foreign('academic_degree_id', 'person_qualifications_academic_degree_fk')
                ->references('id')->on('ref.academic_degrees')->restrictOnDelete();
            $table->foreign('qualification_type_id', 'person_qualifications_qualification_type_fk')
                ->references('id')->on('ref.qualification_types')->restrictOnDelete();
            $table->index('person_id', 'person_qualifications_person_id_index');
            $table->index('academic_degree_id', 'person_qualifications_academic_degree_id_index');
            $table->index('qualification_type_id', 'person_qualifications_qualification_type_id_index');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE "hr"."person_qualifications"
                ADD CONSTRAINT "person_qualifications_identity_present_check"
                CHECK ("academic_degree_id" IS NOT NULL OR "qualification_type_id" IS NOT NULL)
            SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE "hr"."person_qualifications"
                ADD CONSTRAINT "person_qualifications_identity_unique"
                UNIQUE NULLS NOT DISTINCT ("person_id", "academic_degree_id", "qualification_type_id")
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('hr.person_qualifications');
    }
};
