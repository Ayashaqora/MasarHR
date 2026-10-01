<?php

namespace Tests\Feature\HumanResources;

use App\Modules\HumanResources\Application\Commands\EndEmploymentRelationship;
use App\Modules\HumanResources\Application\Commands\EndFullSecondment;
use App\Modules\HumanResources\Application\Commands\RecordEmploymentCategoryPeriod;
use App\Modules\HumanResources\Application\Commands\RecordEmploymentContractPeriod;
use App\Modules\HumanResources\Application\Commands\RecordEmploymentJobTitlePeriod;
use App\Modules\HumanResources\Application\Commands\RecordEmploymentStatusPeriod;
use App\Modules\HumanResources\Application\Commands\RecordPersonQualification;
use App\Modules\HumanResources\Application\Commands\StartFullSecondment;
use App\Modules\HumanResources\Application\Commands\StartWorkplaceAssignment;
use App\Modules\HumanResources\Application\Commands\TransferEmployee;
use App\Modules\HumanResources\Application\Queries\ListPersonQualifications;
use App\Modules\HumanResources\Domain\Exceptions\DuplicatePersonQualificationException;
use App\Modules\HumanResources\Domain\Exceptions\InvalidPersonQualificationAcademicDegreeException;
use App\Modules\HumanResources\Domain\Exceptions\InvalidPersonQualificationTypeException;
use App\Modules\HumanResources\Domain\Exceptions\PersonQualificationIdentityMissingException;
use App\Modules\HumanResources\Infrastructure\Authorization\HumanResourcesPermissionCatalog as Perm;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\Person;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\PersonQualification;
use App\Modules\Platform\Infrastructure\Persistence\Postgres\PostgresErrorClassifier as Errors;
use App\Modules\Reference\Application\Commands\DeactivateAcademicDegree;
use App\Modules\Reference\Application\Commands\DeactivateQualificationType;
use App\Modules\Reference\Infrastructure\Authorization\ReferencePermissionCatalog;
use App\Modules\Reference\Infrastructure\Persistence\Eloquent\AcademicDegree;
use App\Modules\Reference\Infrastructure\Persistence\Eloquent\QualificationType;
use App\Modules\Security\Infrastructure\Persistence\Eloquent\Principal;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * S23 Person Qualification Foundation (docs/person-qualification-foundation-specification.md,
 * ADR-S23-001 / ADR-S23-DECISIONS): Person-owned qualification facts identified by an optional
 * academic degree plus an optional qualification type (at least one), multiple per Person, no
 * date, no specialty, no primary/highest; active-at-record-time references; exact-duplicate
 * protection in PostgreSQL; independence from every employment lifecycle event and reappointment;
 * separation from job title / category / contract / supervisory data; plain hr.* RBAC; audit. Both
 * catalogs are deliberately empty (S13), so every test supplies synthetic values. The cross-session
 * duplicate race lives in ConcurrencyTest.
 */
class PersonQualificationFoundationTest extends HumanResourcesTestCase
{
    // ---------------------------------------------------------------------
    // Basic
    // ---------------------------------------------------------------------

    public function test_a_qualification_can_be_identified_by_a_degree_a_type_or_both(): void
    {
        $person = $this->createPersonRecord();
        $degree = $this->createSyntheticAcademicDegree();
        $type = $this->createSyntheticQualificationType();

        $byDegree = $this->record($person, $degree, null);
        $byType = $this->record($person, null, $type);
        $byBoth = $this->record($person, $degree, $type);

        $this->assertSame([$degree->id, null], [$byDegree->academic_degree_id, $byDegree->qualification_type_id]);
        $this->assertSame([null, $type->id], [$byType->academic_degree_id, $byType->qualification_type_id]);
        $this->assertSame([$degree->id, $type->id], [$byBoth->academic_degree_id, $byBoth->qualification_type_id]);
        $this->assertSame(3, $this->qualificationCount($person), 'multiple qualifications per Person; nothing is overwritten');
    }

    public function test_store_returns_201_and_index_lists_all_facts_without_ranking(): void
    {
        $this->actingAsHrAdministrator();
        $person = $this->createPersonRecord();
        $d1 = $this->createSyntheticAcademicDegree();
        $t1 = $this->createSyntheticQualificationType();

        $this->postJson($this->url($person), ['academic_degree_id' => $d1->id])
            ->assertStatus(201)
            ->assertJsonPath('person_id', $person->id)
            ->assertJsonPath('academic_degree_id', $d1->id)
            ->assertJsonPath('qualification_type_id', null);
        $this->postJson($this->url($person), ['qualification_type_id' => $t1->id])->assertStatus(201);

        $response = $this->getJson($this->url($person))->assertOk();
        $this->assertCount(2, $response->json());
        foreach ($response->json() as $row) {
            // S41 (R1-D44/D49) supersedes the S23 "no primary flag": the Primary designation is the ONLY ranking-like field;
            // there is still no highest/current flag, date or specialty.
            $this->assertSame(['id', 'person_id', 'academic_degree_id', 'qualification_type_id', 'is_primary'], array_keys($row),
                'only the S41 Primary designation is exposed; no highest/current flag, date, or specialty');
        }
    }

    public function test_a_person_with_no_qualification_has_an_empty_list_and_no_row_represents_none(): void
    {
        $this->actingAsHrAdministrator();
        $person = $this->createPersonRecord();

        // «بدون» is not a qualification fact: it is represented by zero rows, never by a record.
        $this->getJson($this->url($person))->assertOk()->assertJsonCount(0);
        $this->assertSame(0, $this->qualificationCount($person));
    }

    public function test_neither_reference_supplied_is_rejected_by_api_command_and_database(): void
    {
        $this->actingAsHrAdministrator();
        $person = $this->createPersonRecord();

        $this->postJson($this->url($person), [])
            ->assertStatus(422)->assertJsonValidationErrors(['academic_degree_id', 'qualification_type_id']);
        $this->postJson($this->url($person), ['academic_degree_id' => null, 'qualification_type_id' => null])
            ->assertStatus(422);
        $this->postJson($this->url($person), ['academic_degree_id' => 'not-a-uuid'])->assertStatus(422);

        try {
            $this->record($person, null, null);
            $this->fail('an empty identity must be rejected');
        } catch (PersonQualificationIdentityMissingException) {
        }

        $error = $this->queryError(fn () => $this->insertRaw($person, null, null));
        $this->assertTrue(Errors::isCheckViolation($error), 'the database itself forbids an empty identity');
        $this->assertSame(0, $this->qualificationCount($person));
    }

    // ---------------------------------------------------------------------
    // Reference
    // ---------------------------------------------------------------------

    public function test_missing_references_are_404_and_inactive_references_are_rejected(): void
    {
        $this->actingAsHrAdministrator();
        $person = $this->createPersonRecord();
        $auditBefore = $this->auditEntriesCount();

        $this->postJson($this->url($person), ['academic_degree_id' => (string) Str::uuid7()])->assertNotFound();
        $this->postJson($this->url($person), ['qualification_type_id' => (string) Str::uuid7()])->assertNotFound();
        $this->postJson($this->url($person), ['academic_degree_id' => $this->createSyntheticAcademicDegree(active: false)->id])
            ->assertStatus(422)->assertJsonValidationErrors(['academic_degree_id']);
        $this->postJson($this->url($person), [
            'academic_degree_id' => $this->createSyntheticAcademicDegree()->id,
            'qualification_type_id' => $this->createSyntheticQualificationType(active: false)->id,
        ])->assertStatus(422)->assertJsonValidationErrors(['qualification_type_id']);

        $this->assertSame(0, $this->qualificationCount($person), 'no partial row');
        $this->assertSame($auditBefore, $this->auditEntriesCount(), 'no misleading success audit');

        $this->expectException(InvalidPersonQualificationAcademicDegreeException::class);
        $this->record($person, $this->createSyntheticAcademicDegree(active: false), null);
    }

    public function test_an_inactive_qualification_type_is_rejected_by_the_command(): void
    {
        $this->expectException(InvalidPersonQualificationTypeException::class);
        $this->record($this->createPersonRecord(), null, $this->createSyntheticQualificationType(active: false));
    }

    public function test_later_deactivation_preserves_existing_qualifications_and_blocks_new_ones(): void
    {
        $this->actingAsHrAdministrator();
        $person = $this->createPersonRecord();
        $degree = $this->createSyntheticAcademicDegree();
        $type = $this->createSyntheticQualificationType();
        $existing = $this->record($person, $degree, $type);
        $before = $this->snapshot($person);

        app(DeactivateAcademicDegree::class)->handle($degree, $degree->version);
        app(DeactivateQualificationType::class)->handle($type, $type->version);

        $this->assertSame($before, $this->snapshot($person), 'deactivation never alters existing facts');
        $this->getJson($this->url($person))->assertOk()->assertJsonPath('0.id', $existing->id);
        $this->postJson($this->url($person), ['academic_degree_id' => $degree->id])->assertStatus(422);
    }

    public function test_referenced_catalog_values_cannot_be_hard_deleted_and_foreign_keys_are_enforced(): void
    {
        $person = $this->createPersonRecord();
        $degree = $this->createSyntheticAcademicDegree();
        $type = $this->createSyntheticQualificationType();
        $this->record($person, $degree, $type);

        $this->assertTrue(Errors::isForeignKeyViolation($this->queryError(fn () => DB::table('ref.academic_degrees')->where('id', $degree->id)->delete())));
        $this->assertTrue(Errors::isForeignKeyViolation($this->queryError(fn () => DB::table('ref.qualification_types')->where('id', $type->id)->delete())));
        $this->assertTrue(Errors::isForeignKeyViolation($this->queryError(fn () => DB::table('hr.persons')->where('id', $person->id)->delete())), 'a Person with qualifications cannot be hard-deleted');
        $this->assertTrue(Errors::isForeignKeyViolation($this->queryError(fn () => DB::table('hr.person_qualifications')->insert([
            'id' => (string) Str::uuid7(), 'person_id' => (string) Str::uuid7(), 'academic_degree_id' => $degree->id, 'created_at' => now(),
        ]))));
        $this->assertTrue(Errors::isForeignKeyViolation($this->queryError(fn () => $this->insertRaw($person, (string) Str::uuid7(), null))));
    }

    // ---------------------------------------------------------------------
    // Duplicates
    // ---------------------------------------------------------------------

    public function test_the_exact_same_fact_is_rejected_but_different_combinations_are_allowed(): void
    {
        $this->actingAsHrAdministrator();
        $person = $this->createPersonRecord();
        $degree = $this->createSyntheticAcademicDegree();
        $type = $this->createSyntheticQualificationType();
        $this->record($person, $degree, null);
        $this->record($person, null, $type);
        $this->record($person, $degree, $type);
        $auditBefore = $this->auditEntriesCount();

        foreach ([['academic_degree_id' => $degree->id], ['qualification_type_id' => $type->id], ['academic_degree_id' => $degree->id, 'qualification_type_id' => $type->id]] as $payload) {
            $this->postJson($this->url($person), $payload)->assertStatus(409);
        }

        $this->assertSame(3, $this->qualificationCount($person));
        $this->assertSame($auditBefore, $this->auditEntriesCount());

        // Same degree with a different type is a different fact; another Person may hold the same one.
        $this->record($person, $degree, $this->createSyntheticQualificationType());
        $this->record($this->createPersonRecord(), $degree, $type);
        $this->assertSame(4, $this->qualificationCount($person));
    }

    public function test_duplicate_protection_is_enforced_by_postgresql_with_null_as_a_value(): void
    {
        $person = $this->createPersonRecord();
        $degree = $this->createSyntheticAcademicDegree();
        $this->insertRaw($person, $degree->id, null);

        $error = $this->queryError(fn () => $this->insertRaw($person, $degree->id, null));
        $this->assertTrue(Errors::isUniqueViolation($error), 'UNIQUE NULLS NOT DISTINCT — (degree, NULL) cannot repeat');

        $this->expectException(DuplicatePersonQualificationException::class);
        DB::transaction(fn () => $this->record($person, $degree, null));
    }

    // ---------------------------------------------------------------------
    // Person lifecycle & reappointment
    // ---------------------------------------------------------------------

    public function test_every_employment_lifecycle_event_leaves_qualifications_untouched(): void
    {
        $person = $this->createPersonRecord();
        $this->record($person, $this->createSyntheticAcademicDegree(), null);
        $this->record($person, null, $this->createSyntheticQualificationType());
        $before = $this->snapshot($person);
        $check = fn (string $event) => $this->assertSame($before, $this->snapshot($person), "{$event} must not modify qualifications");

        $relationship = $this->createEmploymentRelationship($person, 'contract');
        $check('relationship creation');
        $this->recordPlacement($relationship, $this->createUnit(), '2026-01-15');
        $check('placement');
        app(StartFullSecondment::class)->handle($relationship, $this->createUnit(), '2026-02-01');
        app(EndFullSecondment::class)->handle($relationship, '2026-03-01');
        $check('secondment');
        app(StartWorkplaceAssignment::class)->handle($relationship, $this->createUnit(), '2026-03-15', $this->assignmentDecisionType());
        $check('workplace assignment');
        app(TransferEmployee::class)->handle($relationship, $this->createUnit(), '2026-04-01', $this->transferDecisionType());
        $check('transfer');
        app(RecordEmploymentCategoryPeriod::class)->handle($relationship, $this->employmentCategory('grade_2'), '2026-01-01');
        $check('category change');
        app(RecordEmploymentJobTitlePeriod::class)->handle($relationship, $this->createSyntheticJobTitle(), '2026-01-01');
        $check('job-title change');
        $contractType = $this->createSyntheticContractType();
        app(RecordEmploymentContractPeriod::class)->handle($relationship, $contractType, '2026-01-01', '2026-07-01');
        app(RecordEmploymentContractPeriod::class)->handle($relationship, $contractType, '2026-07-01', '2027-07-01');
        $check('contract recording/renewal');
        app(RecordEmploymentStatusPeriod::class)->handle($person, $relationship, $this->statusDetail('traveling'), '2026-10-01');
        $check('status change');
        app(RecordEmploymentStatusPeriod::class)->handle($person, $relationship, $this->statusDetail('resigned'), '2026-11-01');
        $check('status-triggered termination / contract end');
        $this->assertSame('KNOWN', $relationship->refresh()->end_knowledge_state);
    }

    public function test_reappointment_reuses_the_same_person_facts_without_duplication_and_survives_terminal_ending(): void
    {
        $this->actingAsHrAdministrator();
        $person = $this->createPersonRecord();
        $qualification = $this->record($person, $this->createSyntheticAcademicDegree(), null);
        $first = $this->createEmploymentRelationship($person, 'permanent');
        app(EndEmploymentRelationship::class)->handle($person, $first, $first->version, '2026-06-01', false);

        $this->createEmploymentRelationship($person, 'contract', null, '2026-08-01');

        $this->assertSame(1, $this->qualificationCount($person), 'reappointment never copies qualifications');
        $this->getJson($this->url($person))->assertOk()->assertJsonCount(1)->assertJsonPath('0.id', $qualification->id);

        $other = $this->createPersonRecord();
        $this->record($other, $this->createSyntheticAcademicDegree(), null);
        $relationship = $this->createEmploymentRelationship($other, 'contract');
        app(RecordEmploymentStatusPeriod::class)->handle($other, $relationship, $this->statusDetail('deceased'), '2026-10-15');
        $this->assertTrue($other->refresh()->is_terminal);
        $this->assertSame(1, $this->qualificationCount($other), 'a terminal ending does not delete Person qualifications');
    }

    // ---------------------------------------------------------------------
    // Separation / legacy
    // ---------------------------------------------------------------------

    public function test_recording_a_qualification_infers_or_changes_nothing_else(): void
    {
        $person = $this->createPersonRecord();
        $relationship = $this->createEmploymentRelationship($person, 'contract');
        $tables = ['employment_category_periods', 'employment_job_title_periods', 'employment_contract_periods', 'employment_status_periods', 'organizational_placement_periods'];
        $before = array_map(fn ($t) => DB::table("hr.{$t}")->where('employment_relationship_id', $relationship->id)->count(), $tables);
        $relationshipBefore = (array) DB::table('hr.employment_relationships')->where('id', $relationship->id)->first();
        $personBefore = (array) DB::table('hr.persons')->where('id', $person->id)->first();
        $refCounts = fn () => array_map(fn ($t) => DB::table("ref.{$t}")->count(), ['job_titles', 'employment_categories', 'specialties', 'supervisory_titles']);
        $refBefore = $refCounts();

        $this->record($person, $this->createSyntheticAcademicDegree(), $this->createSyntheticQualificationType());

        $this->assertSame($before, array_map(fn ($t) => DB::table("hr.{$t}")->where('employment_relationship_id', $relationship->id)->count(), $tables), 'no job title, category, contract, status or placement is inferred');
        $this->assertEquals($relationshipBefore, (array) DB::table('hr.employment_relationships')->where('id', $relationship->id)->first());
        $this->assertEquals($personBefore, (array) DB::table('hr.persons')->where('id', $person->id)->first());
        $this->assertSame($refBefore, $refCounts(), 'no job title, category, specialty or supervisory value is created');
    }

    public function test_the_table_has_no_date_specialty_ranking_or_employment_columns(): void
    {
        $types = DB::table('information_schema.columns')
            ->where('table_schema', 'hr')->where('table_name', 'person_qualifications')
            ->pluck('data_type', 'column_name')->all();
        ksort($types);

        $this->assertSame([
            'academic_degree_id' => 'uuid',
            'created_at' => 'timestamp with time zone',
            'id' => 'uuid',
            'is_primary' => 'boolean', // S41 SCHEMA-02 (R1-D44): the Primary designation
            'person_id' => 'uuid',
            'qualification_type_id' => 'uuid',
        ], $types, 'no acquisition/graduation date, knowledge state, specialty, highest flag, institution, or employment_relationship_id (is_primary is the S41 designation)');

        foreach (DB::table('information_schema.columns')->where('table_schema', 'hr')->where('table_name', 'persons')->pluck('column_name') as $column) {
            $this->assertStringNotContainsString('qualification', $column, 'no single snapshot qualification column on Person');
        }
    }

    // ---------------------------------------------------------------------
    // Security
    // ---------------------------------------------------------------------

    public function test_authentication_and_hr_permissions_are_enforced(): void
    {
        $person = $this->createPersonRecord();
        $payload = fn () => ['academic_degree_id' => $this->createSyntheticAcademicDegree()->id];

        $this->getJson($this->url($person))->assertUnauthorized();
        $this->postJson($this->url($person), [])->assertUnauthorized();

        $this->actingAs($this->createPrincipal(), 'web');
        $this->getJson($this->url($person))->assertForbidden();
        $this->postJson($this->url($person), $payload())->assertForbidden();

        $this->principalWithPermissions([Perm::PERSON_QUALIFICATIONS_VIEW]);
        $this->getJson($this->url($person))->assertOk();
        $this->postJson($this->url($person), $payload())->assertForbidden();

        $this->principalWithPermissions([Perm::PERSON_QUALIFICATIONS_RECORD]);
        $this->postJson($this->url($person), $payload())->assertStatus(201);
    }

    public function test_reference_and_other_hr_permissions_never_grant_qualification_recording(): void
    {
        $person = $this->createPersonRecord();
        $payload = fn () => ['academic_degree_id' => $this->createSyntheticAcademicDegree()->id];

        $this->principalWithPermissions(ReferencePermissionCatalog::ALL);
        $this->postJson($this->url($person), $payload())->assertForbidden();
        $this->getJson($this->url($person))->assertForbidden();
        $this->getJson('/api/v1/reference/academic-degrees')->assertOk();

        $this->principalWithPermissions(array_values(array_diff(Perm::ALL, [Perm::PERSON_QUALIFICATIONS_VIEW, Perm::PERSON_QUALIFICATIONS_RECORD])));
        $this->postJson($this->url($person), $payload())->assertForbidden();

        $this->assertSame(0, $this->qualificationCount($person));
    }

    public function test_unknown_person_is_404_and_no_patch_or_delete_route_exists(): void
    {
        $this->actingAsHrAdministrator();
        $person = $this->createPersonRecord();

        $this->getJson('/api/v1/hr/persons/'.Str::uuid7().'/qualifications')->assertNotFound();
        $this->patchJson($this->url($person), [])->assertStatus(405);
        $this->deleteJson($this->url($person))->assertStatus(405);
    }

    // ---------------------------------------------------------------------
    // Audit
    // ---------------------------------------------------------------------

    public function test_a_successful_record_is_audited_with_stable_codes_and_no_pii(): void
    {
        $principal = $this->actingAsHrAdministrator();
        $person = $this->createPersonRecord();
        $degree = $this->createSyntheticAcademicDegree();

        $response = $this->postJson($this->url($person), ['academic_degree_id' => $degree->id])->assertStatus(201);

        $entry = $this->latestAuditEntryFor('hr.person_qualification.record');
        $this->assertSame($principal->id, $entry->actor_principal_id);
        $this->assertSame('hr_person_qualification', $entry->target_type);
        $this->assertSame($response->json('id'), $entry->target_id);
        $this->assertEquals(['person_id' => $person->id, 'academic_degree_id' => $degree->id, 'qualification_type_id' => null, 'is_primary' => true], $entry->changes, 'S41: the first qualification is Primary and the audit says so');
        $this->assertEquals(['academic_degree_code' => $degree->code], $entry->metadata);
        $this->assertStringNotContainsString($person->national_id, json_encode([$entry->changes, $entry->metadata]));
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    private function record(Person $person, ?AcademicDegree $degree, ?QualificationType $type): PersonQualification
    {
        return app(RecordPersonQualification::class)->handle($person, $degree, $type);
    }

    private function url(Person $person): string
    {
        return "/api/v1/hr/persons/{$person->id}/qualifications";
    }

    private function qualificationCount(Person $person): int
    {
        return app(ListPersonQualifications::class)($person)->count();
    }

    /** @return list<array<string, mixed>> */
    private function snapshot(Person $person): array
    {
        return DB::table('hr.person_qualifications')->where('person_id', $person->id)->orderBy('id')
            ->get()->map(fn ($row) => (array) $row)->all();
    }

    private function insertRaw(Person $person, ?string $degreeId, ?string $typeId): void
    {
        DB::table('hr.person_qualifications')->insert([
            'id' => (string) Str::uuid7(),
            'person_id' => $person->id,
            'academic_degree_id' => $degreeId,
            'qualification_type_id' => $typeId,
            'created_at' => now(),
        ]);
    }

    private function queryError(callable $work): QueryException
    {
        try {
            DB::transaction(fn () => $work());
        } catch (QueryException $e) {
            return $e;
        }

        $this->fail('Expected a QueryException.');
    }

    private function principalWithPermissions(array $permissionCodes): Principal
    {
        $principal = $this->createPrincipal();
        $role = $this->createRoleWithPermissions($permissionCodes);
        $this->assignRole($principal, $role);
        $this->actingAs($principal, 'web');

        return $principal;
    }
}
