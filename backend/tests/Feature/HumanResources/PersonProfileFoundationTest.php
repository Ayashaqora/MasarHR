<?php

namespace Tests\Feature\HumanResources;

use App\Modules\HumanResources\Application\Commands\CreatePerson;
use App\Modules\HumanResources\Application\Commands\UpdatePersonProfile;
use App\Modules\HumanResources\Domain\Exceptions\InvalidPersonProfileException;
use App\Modules\HumanResources\Domain\Exceptions\PersonStaleVersionException;
use App\Modules\HumanResources\Infrastructure\Authorization\HumanResourcesPermissionCatalog as Perm;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\Person;
use App\Modules\Platform\Infrastructure\Persistence\Postgres\PostgresErrorClassifier as Errors;
use App\Modules\Reference\Application\Commands\DeactivateGender;
use App\Modules\Reference\Application\Commands\DeactivateMaritalStatus;
use App\Modules\Reference\Infrastructure\Authorization\ReferencePermissionCatalog;
use App\Modules\Security\Infrastructure\Persistence\Eloquent\Principal;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * S24 Person Profile Foundation (docs/person-profile-foundation-specification.md, ADR-S24-001):
 * five CURRENT, mutable attributes on hr.persons — full_name_ar, gender_id, marital_status_id,
 * birth_date, birth_place. Required (except birth_place) for NEW Persons as an application
 * invariant; nullable in the database so legacy rows migrate untouched; updated only through the
 * explicit UpdatePersonProfile command; references must exist and be active; PII-safe audit; no
 * history table, no age column, no name parts. All data synthetic.
 */
class PersonProfileFoundationTest extends HumanResourcesTestCase
{
    private const PROFILE_COLUMNS = ['full_name_ar', 'gender_id', 'marital_status_id', 'birth_date', 'birth_place'];

    // ---------------------------------------------------------------------
    // Create (§S24.7)
    // ---------------------------------------------------------------------

    public function test_create_stores_the_full_profile_and_returns_it(): void
    {
        $this->actingAsHrAdministrator();
        $female = $this->gender('female');
        $married = $this->maritalStatus('married');

        $response = $this->postJson('/api/v1/hr/persons', $this->personPayload(null, [
            'full_name_ar' => '  اسم   تجريبي  ',
            'gender_id' => $female->id,
            'marital_status_id' => $married->id,
            'birth_date' => '1985-02-28',
            'birth_place' => '  مكان تجريبي ',
        ]))->assertStatus(201);

        $response->assertJsonPath('full_name_ar', 'اسم   تجريبي')
            ->assertJsonPath('gender_id', $female->id)
            ->assertJsonPath('marital_status_id', $married->id)
            ->assertJsonPath('birth_date', '1985-02-28')
            ->assertJsonPath('birth_place', 'مكان تجريبي')
            ->assertJsonPath('version', 1);

        $row = DB::table('hr.persons')->where('id', $response->json('id'))->first();
        $this->assertSame('اسم   تجريبي', $row->full_name_ar, 'trim only — inner spacing, word count and script are never rewritten');
        $this->assertSame('1985-02-28', $row->birth_date);
    }

    public function test_birth_place_is_optional_on_create(): void
    {
        $this->actingAsHrAdministrator();

        $this->postJson('/api/v1/hr/persons', $this->personPayload())
            ->assertStatus(201)->assertJsonPath('birth_place', null);
        $this->postJson('/api/v1/hr/persons', $this->personPayload(null, ['birth_place' => null]))
            ->assertStatus(201)->assertJsonPath('birth_place', null);
    }

    public function test_each_required_profile_field_is_required_on_create(): void
    {
        $this->actingAsHrAdministrator();
        $before = Person::query()->count();
        $auditBefore = $this->auditEntriesCount();

        foreach (['full_name_ar', 'gender_id', 'marital_status_id', 'birth_date'] as $field) {
            $payload = $this->personPayload();
            unset($payload[$field]);
            $this->postJson('/api/v1/hr/persons', $payload)->assertStatus(422)->assertJsonValidationErrors([$field]);
            $this->postJson('/api/v1/hr/persons', [...$this->personPayload(), $field => null])->assertStatus(422)->assertJsonValidationErrors([$field]);
        }

        $this->assertSame($before, Person::query()->count());
        $this->assertSame($auditBefore, $this->auditEntriesCount(), 'no success audit for a rejected create');
    }

    public function test_blank_name_and_blank_birth_place_are_rejected_on_create(): void
    {
        $this->actingAsHrAdministrator();
        $before = Person::query()->count();

        $this->postJson('/api/v1/hr/persons', $this->personPayload(null, ['full_name_ar' => '   ']))
            ->assertStatus(422)->assertJsonValidationErrors(['full_name_ar']);
        // Over HTTP the framework's global TrimStrings/ConvertEmptyStringsToNull middleware turns a
        // whitespace-only birth_place into null — "not supplied / unknown", never a stored blank.
        $id = $this->postJson('/api/v1/hr/persons', $this->personPayload(null, ['birth_place' => "  \t "]))
            ->assertStatus(201)->assertJsonPath('birth_place', null)->json('id');
        $this->assertNull(DB::table('hr.persons')->where('id', $id)->value('birth_place'));
        $before++;

        try {
            app(CreatePerson::class)->handle($this->uniqueNationalId(), 'اسم', $this->gender('male'), $this->maritalStatus('single'), '1990-01-01', '   ');
            $this->fail('the command itself rejects a blank birth_place');
        } catch (InvalidPersonProfileException $e) {
            $this->assertSame('birth_place', $e->field);
        }

        $this->expectException(InvalidPersonProfileException::class);
        try {
            app(CreatePerson::class)->handle($this->uniqueNationalId(), '   ', $this->gender('male'), $this->maritalStatus('single'), '1990-01-01');
        } finally {
            $this->assertSame($before, Person::query()->count());
        }
    }

    public function test_future_birth_date_is_rejected_and_today_is_accepted(): void
    {
        $this->actingAsHrAdministrator();

        $this->postJson('/api/v1/hr/persons', $this->personPayload(null, ['birth_date' => Carbon::tomorrow()->toDateString()]))
            ->assertStatus(422)->assertJsonValidationErrors(['birth_date']);
        $this->postJson('/api/v1/hr/persons', $this->personPayload(null, ['birth_date' => 'not-a-date']))
            ->assertStatus(422)->assertJsonValidationErrors(['birth_date']);

        // No age policy is invented (ADR-S24-001 §5): the only rule is "not in the future".
        $this->postJson('/api/v1/hr/persons', $this->personPayload(null, ['birth_date' => Carbon::today()->toDateString()]))
            ->assertStatus(201);
    }

    public function test_create_does_not_change_the_s09_national_id_rules(): void
    {
        $this->actingAsHrAdministrator();
        $nationalId = $this->uniqueNationalId();

        $this->postJson('/api/v1/hr/persons', $this->personPayload($nationalId))->assertStatus(201);
        $this->postJson('/api/v1/hr/persons', $this->personPayload($nationalId, ['full_name_ar' => 'اسم آخر']))
            ->assertStatus(409);
        $this->postJson('/api/v1/hr/persons', $this->personPayload(null, ['national_id' => '']))
            ->assertStatus(422)->assertJsonValidationErrors(['national_id']);
    }

    // ---------------------------------------------------------------------
    // References (§S24.9–§S24.10)
    // ---------------------------------------------------------------------

    public function test_missing_references_are_404_and_inactive_references_are_rejected_on_create(): void
    {
        $this->actingAsHrAdministrator();
        $before = Person::query()->count();
        $auditBefore = $this->auditEntriesCount();

        $this->postJson('/api/v1/hr/persons', $this->personPayload(null, ['gender_id' => (string) Str::uuid7()]))->assertNotFound();
        $this->postJson('/api/v1/hr/persons', $this->personPayload(null, ['marital_status_id' => (string) Str::uuid7()]))->assertNotFound();
        $this->postJson('/api/v1/hr/persons', $this->personPayload(null, ['gender_id' => 'not-a-uuid']))
            ->assertStatus(422)->assertJsonValidationErrors(['gender_id']);

        $this->deactivateGender('female');
        $this->postJson('/api/v1/hr/persons', $this->personPayload(null, ['gender_id' => $this->gender('female')->id]))
            ->assertStatus(422)->assertJsonValidationErrors(['gender_id']);

        $this->deactivateMaritalStatus('widowed');
        $this->postJson('/api/v1/hr/persons', $this->personPayload(null, ['marital_status_id' => $this->maritalStatus('widowed')->id]))
            ->assertStatus(422)->assertJsonValidationErrors(['marital_status_id']);

        $this->assertSame($before, Person::query()->count(), 'no partial Person row');
        $this->assertSame($auditBefore, $this->auditEntriesCount(), 'no misleading success audit');
    }

    public function test_deactivating_a_referenced_value_keeps_existing_persons_intact(): void
    {
        $this->actingAsHrAdministrator();
        $person = $this->createPersonRecord();
        $update = app(UpdatePersonProfile::class);
        $person = $update->handle($person, $person->version, [
            'gender' => $this->gender('female'),
            'marital_status' => $this->maritalStatus('divorced'),
        ]);

        $this->deactivateGender('female');
        $this->deactivateMaritalStatus('divorced');

        $this->getJson("/api/v1/hr/persons/{$person->id}")->assertOk()
            ->assertJsonPath('gender_id', $this->gender('female')->id)
            ->assertJsonPath('marital_status_id', $this->maritalStatus('divorced')->id);

        // An unrelated field can still be updated without re-validating the untouched references.
        $this->postJson("/api/v1/hr/persons/{$person->id}/update-profile", ['expected_version' => $person->version, 'birth_place' => 'مكان'])
            ->assertOk()->assertJsonPath('gender_id', $this->gender('female')->id);

        // But re-assigning an inactive value is a NEW assignment and is rejected.
        $this->postJson("/api/v1/hr/persons/{$person->id}/update-profile", ['expected_version' => $person->version + 1, 'gender_id' => $this->gender('female')->id])
            ->assertStatus(422)->assertJsonValidationErrors(['gender_id']);
    }

    public function test_referenced_catalog_rows_cannot_be_deleted_underneath_a_person(): void
    {
        $person = $this->createPersonRecord();

        $error = $this->queryError(fn () => DB::table('ref.genders')->where('id', $person->gender_id)->delete());
        $this->assertTrue(Errors::isForeignKeyViolation($error), 'RESTRICT FK to ref.genders');

        $error = $this->queryError(fn () => DB::table('ref.marital_statuses')->where('id', $person->marital_status_id)->delete());
        $this->assertTrue(Errors::isForeignKeyViolation($error), 'RESTRICT FK to ref.marital_statuses');
    }

    public function test_gender_and_marital_status_are_independent(): void
    {
        $this->actingAsHrAdministrator();

        // Every seeded combination is accepted; neither value is inferred from or constrained by the other.
        foreach (['male', 'female'] as $g) {
            foreach (['single', 'married', 'divorced', 'widowed'] as $m) {
                $this->postJson('/api/v1/hr/persons', $this->personPayload(null, [
                    'gender_id' => $this->gender($g)->id,
                    'marital_status_id' => $this->maritalStatus($m)->id,
                ]))->assertStatus(201)
                    ->assertJsonPath('gender_id', $this->gender($g)->id)
                    ->assertJsonPath('marital_status_id', $this->maritalStatus($m)->id);
            }
        }
    }

    // ---------------------------------------------------------------------
    // Legacy (§S24.11)
    // ---------------------------------------------------------------------

    public function test_legacy_persons_keep_null_profile_values_and_are_readable(): void
    {
        $this->actingAsHrAdministrator();
        $legacy = $this->insertLegacyPerson();

        $response = $this->getJson("/api/v1/hr/persons/{$legacy->id}")->assertOk();
        foreach (self::PROFILE_COLUMNS as $column) {
            $this->assertArrayHasKey($column, $response->json());
            $this->assertNull($response->json($column), "legacy {$column} is exposed as null — never a placeholder");
        }

        $this->getJson('/api/v1/hr/persons/lookup?national_id='.$legacy->national_id)->assertOk()
            ->assertJsonPath('full_name_ar', null);
    }

    public function test_a_legacy_person_can_be_completed_one_field_at_a_time(): void
    {
        $this->actingAsHrAdministrator();
        $legacy = $this->insertLegacyPerson();

        $this->postJson("/api/v1/hr/persons/{$legacy->id}/update-profile", ['expected_version' => 1, 'full_name_ar' => 'اسم مكتمل'])
            ->assertOk()
            ->assertJsonPath('full_name_ar', 'اسم مكتمل')
            ->assertJsonPath('gender_id', null)
            ->assertJsonPath('marital_status_id', null)
            ->assertJsonPath('birth_date', null)
            ->assertJsonPath('version', 2);

        $this->postJson("/api/v1/hr/persons/{$legacy->id}/update-profile", ['expected_version' => 2, 'birth_date' => '1970-07-07'])
            ->assertOk()->assertJsonPath('birth_date', '1970-07-07')->assertJsonPath('gender_id', null);
    }

    public function test_the_database_permits_nulls_but_rejects_blank_strings(): void
    {
        $legacy = $this->insertLegacyPerson();
        $this->assertNull(DB::table('hr.persons')->where('id', $legacy->id)->value('full_name_ar'));

        $error = $this->queryError(fn () => DB::table('hr.persons')->where('id', $legacy->id)->update(['full_name_ar' => '  ']));
        $this->assertTrue(Errors::isCheckViolation($error));

        $error = $this->queryError(fn () => DB::table('hr.persons')->where('id', $legacy->id)->update(['birth_place' => '']));
        $this->assertTrue(Errors::isCheckViolation($error));

        $error = $this->queryError(fn () => DB::table('hr.persons')->where('id', $legacy->id)->update(['gender_id' => (string) Str::uuid7()]));
        $this->assertTrue(Errors::isForeignKeyViolation($error));
    }

    // ---------------------------------------------------------------------
    // Update (§S24.8)
    // ---------------------------------------------------------------------

    public function test_each_field_can_be_updated_alone_leaving_the_others_untouched(): void
    {
        $this->actingAsHrAdministrator();
        $person = $this->createPersonRecord();
        $version = $person->version;
        $url = "/api/v1/hr/persons/{$person->id}/update-profile";

        $cases = [
            ['full_name_ar', 'اسم معدل', 'full_name_ar', 'اسم معدل'],
            ['gender_id', $this->gender('female')->id, 'gender_id', $this->gender('female')->id],
            ['marital_status_id', $this->maritalStatus('married')->id, 'marital_status_id', $this->maritalStatus('married')->id],
            ['birth_date', '1991-12-31', 'birth_date', '1991-12-31'],
            ['birth_place', 'مكان الميلاد', 'birth_place', 'مكان الميلاد'],
        ];

        foreach ($cases as [$field, $value, $jsonKey, $expected]) {
            $before = $this->profileOf($person);
            $response = $this->postJson($url, ['expected_version' => $version, $field => $value])->assertOk();
            $version++;
            $response->assertJsonPath($jsonKey, $expected)->assertJsonPath('version', $version);

            $after = $this->profileOf($person);
            foreach (self::PROFILE_COLUMNS as $column) {
                if ($column !== $field) {
                    $this->assertSame($before[$column], $after[$column], "updating {$field} must not touch {$column}");
                }
            }
        }
    }

    public function test_multiple_fields_are_updated_atomically(): void
    {
        $this->actingAsHrAdministrator();
        $person = $this->createPersonRecord();

        $this->postJson("/api/v1/hr/persons/{$person->id}/update-profile", [
            'expected_version' => 1,
            'full_name_ar' => 'اسم جديد',
            'gender_id' => $this->gender('female')->id,
            'marital_status_id' => $this->maritalStatus('widowed')->id,
            'birth_date' => '1980-01-15',
            'birth_place' => 'مكان جديد',
        ])->assertOk()->assertJsonPath('version', 2);

        $this->assertSame([
            'full_name_ar' => 'اسم جديد',
            'gender_id' => $this->gender('female')->id,
            'marital_status_id' => $this->maritalStatus('widowed')->id,
            'birth_date' => '1980-01-15',
            'birth_place' => 'مكان جديد',
        ], $this->profileOf($person));
    }

    public function test_a_rejected_update_leaves_all_state_and_audit_intact(): void
    {
        $this->actingAsHrAdministrator();
        $person = $this->createPersonRecord();
        $before = $this->profileOf($person);
        $auditBefore = $this->auditEntriesCount();
        $url = "/api/v1/hr/persons/{$person->id}/update-profile";

        // One valid field plus one invalid field: nothing is applied.
        $this->postJson($url, ['expected_version' => 1, 'full_name_ar' => 'اسم صالح', 'birth_date' => Carbon::tomorrow()->toDateString()])
            ->assertStatus(422)->assertJsonValidationErrors(['birth_date']);
        $this->postJson($url, ['expected_version' => 1, 'birth_place' => 'صالح', 'full_name_ar' => ' '])
            ->assertStatus(422)->assertJsonValidationErrors(['full_name_ar']);
        $this->deactivateMaritalStatus('married');
        $this->postJson($url, ['expected_version' => 1, 'full_name_ar' => 'اسم صالح', 'marital_status_id' => $this->maritalStatus('married')->id])
            ->assertStatus(422)->assertJsonValidationErrors(['marital_status_id']);
        $this->postJson($url, ['expected_version' => 1, 'full_name_ar' => 'اسم صالح', 'gender_id' => (string) Str::uuid7()])
            ->assertNotFound();

        $this->assertSame($before, $this->profileOf($person));
        $this->assertSame(1, (int) DB::table('hr.persons')->where('id', $person->id)->value('version'));
        $this->assertSame($auditBefore, $this->auditEntriesCount());
    }

    public function test_required_fields_cannot_be_cleared_but_birth_place_can(): void
    {
        $this->actingAsHrAdministrator();
        $person = $this->createPersonRecord();
        $url = "/api/v1/hr/persons/{$person->id}/update-profile";

        foreach (['full_name_ar', 'gender_id', 'marital_status_id', 'birth_date'] as $field) {
            $this->postJson($url, ['expected_version' => 1, $field => null])->assertStatus(422)->assertJsonValidationErrors([$field]);
        }

        $this->postJson($url, ['expected_version' => 1, 'birth_place' => 'مكان'])->assertOk();
        $this->postJson($url, ['expected_version' => 2, 'birth_place' => null])->assertOk()->assertJsonPath('birth_place', null);
    }

    public function test_an_empty_update_is_rejected(): void
    {
        $this->actingAsHrAdministrator();
        $person = $this->createPersonRecord();

        $this->postJson("/api/v1/hr/persons/{$person->id}/update-profile", ['expected_version' => 1])
            ->assertStatus(422)->assertJsonValidationErrors(['profile']);
        $this->postJson("/api/v1/hr/persons/{$person->id}/update-profile", ['full_name_ar' => 'اسم'])
            ->assertStatus(422)->assertJsonValidationErrors(['expected_version']);
    }

    public function test_a_stale_version_is_409_and_mutates_nothing(): void
    {
        $this->actingAsHrAdministrator();
        $person = $this->createPersonRecord();
        $url = "/api/v1/hr/persons/{$person->id}/update-profile";

        $this->postJson($url, ['expected_version' => 1, 'full_name_ar' => 'أول تعديل'])->assertOk();
        $auditBefore = $this->auditEntriesCount();

        $this->postJson($url, ['expected_version' => 1, 'full_name_ar' => 'تعديل متأخر'])->assertStatus(409);

        $this->assertSame('أول تعديل', DB::table('hr.persons')->where('id', $person->id)->value('full_name_ar'));
        $this->assertSame($auditBefore, $this->auditEntriesCount());

        $this->expectException(PersonStaleVersionException::class);
        app(UpdatePersonProfile::class)->handle($person->fresh(), 1, ['birth_place' => 'x']);
    }

    public function test_update_cannot_mutate_national_id_terminal_flag_or_employment_data(): void
    {
        $this->actingAsHrAdministrator();
        $person = $this->createPersonRecord();
        $relationship = $this->createEmploymentRelationship($person);
        $relationshipBefore = (array) DB::table('hr.employment_relationships')->where('id', $relationship->id)->first();

        $this->postJson("/api/v1/hr/persons/{$person->id}/update-profile", [
            'expected_version' => 1,
            'full_name_ar' => 'اسم',
            'national_id' => '0000000000',
            'is_terminal' => true,
            'employee_number' => 'HACK-1',
            'effective_from' => '2000-01-01',
            'start_work_date' => '2000-01-01',
        ])->assertOk()->assertJsonPath('national_id', $person->national_id)->assertJsonPath('is_terminal', false);

        $row = DB::table('hr.persons')->where('id', $person->id)->first();
        $this->assertSame($person->national_id, $row->national_id);
        $this->assertFalse((bool) $row->is_terminal);
        $this->assertEquals($relationshipBefore, (array) DB::table('hr.employment_relationships')->where('id', $relationship->id)->first(),
            'the employment relationship (dates, number, type) is untouched');
    }

    public function test_the_command_rejects_any_non_profile_key(): void
    {
        $person = $this->createPersonRecord();

        foreach (['national_id', 'is_terminal', 'gender_id', 'age'] as $key) {
            try {
                app(UpdatePersonProfile::class)->handle($person, 1, [$key => 'x']);
                $this->fail("{$key} must be refused");
            } catch (InvalidArgumentException) {
            }
        }

        $this->assertSame(1, (int) DB::table('hr.persons')->where('id', $person->id)->value('version'));
    }

    public function test_unknown_person_is_404(): void
    {
        $this->actingAsHrAdministrator();

        $this->postJson('/api/v1/hr/persons/'.Str::uuid7().'/update-profile', ['expected_version' => 1, 'full_name_ar' => 'اسم'])
            ->assertNotFound();
    }

    // ---------------------------------------------------------------------
    // No-op updates (CA-S24-01, §S24.8)
    // ---------------------------------------------------------------------

    public function test_resubmitting_every_current_value_is_a_no_op(): void
    {
        $this->actingAsHrAdministrator();
        $person = $this->createPersonRecord();
        $this->postJson($this->updateUrl($person), ['expected_version' => 1, 'birth_place' => 'مكان'])->assertOk();
        $snapshot = $this->rowOf($person);
        $auditBefore = $this->auditEntriesCount();

        $this->postJson($this->updateUrl($person), [
            'expected_version' => 2,
            'full_name_ar' => 'موظف اختبار',
            'gender_id' => $this->gender('male')->id,
            'marital_status_id' => $this->maritalStatus('single')->id,
            'birth_date' => '1990-05-17',
            'birth_place' => 'مكان',
        ])->assertOk()->assertJsonPath('version', 2)->assertJsonPath('full_name_ar', 'موظف اختبار');

        $this->assertSame($snapshot, $this->rowOf($person), 'no UPDATE: profile, version and updated_at unchanged');
        $this->assertSame($auditBefore, $this->auditEntriesCount(), 'no audit entry for a no-op');
    }

    public function test_a_value_equal_after_existing_trim_normalization_is_a_no_op(): void
    {
        $this->actingAsHrAdministrator();
        $person = $this->createPersonRecord();
        $snapshot = $this->rowOf($person);
        $auditBefore = $this->auditEntriesCount();

        $this->postJson($this->updateUrl($person), ['expected_version' => 1, 'full_name_ar' => "  موظف اختبار \t"])
            ->assertOk()->assertJsonPath('version', 1);
        $this->assertSame(1, app(UpdatePersonProfile::class)->handle($person, 1, ['full_name_ar' => ' موظف اختبار '])->version, 'the command itself is a no-op too');

        $this->assertSame($snapshot, $this->rowOf($person));
        $this->assertSame($auditBefore, $this->auditEntriesCount());
    }

    public function test_birth_place_null_to_null_is_a_no_op(): void
    {
        $this->actingAsHrAdministrator();
        $person = $this->createPersonRecord();
        $this->assertNull($person->birth_place);
        $snapshot = $this->rowOf($person);
        $auditBefore = $this->auditEntriesCount();

        $this->postJson($this->updateUrl($person), ['expected_version' => 1, 'birth_place' => null])
            ->assertOk()->assertJsonPath('version', 1)->assertJsonPath('birth_place', null);

        $this->assertSame($snapshot, $this->rowOf($person));
        $this->assertSame($auditBefore, $this->auditEntriesCount());
    }

    public function test_a_stale_version_with_identical_values_is_still_409(): void
    {
        $this->actingAsHrAdministrator();
        $person = $this->createPersonRecord();
        $this->postJson($this->updateUrl($person), ['expected_version' => 1, 'full_name_ar' => 'اسم حالي'])->assertOk();
        $snapshot = $this->rowOf($person);
        $auditBefore = $this->auditEntriesCount();

        // The stale caller submits exactly what is stored now — it must not bypass concurrency.
        $this->postJson($this->updateUrl($person), ['expected_version' => 1, 'full_name_ar' => 'اسم حالي'])->assertStatus(409);
        $this->postJson($this->updateUrl($person), ['expected_version' => 3, 'birth_place' => null])->assertStatus(409);

        $this->assertSame($snapshot, $this->rowOf($person));
        $this->assertSame($auditBefore, $this->auditEntriesCount());

        $this->expectException(PersonStaleVersionException::class);
        app(UpdatePersonProfile::class)->handle($person, 1, ['full_name_ar' => 'اسم حالي']);
    }

    public function test_a_mixed_payload_updates_and_audits_only_the_genuinely_changed_field(): void
    {
        $this->actingAsHrAdministrator();
        $person = $this->createPersonRecord();
        $auditBefore = $this->auditEntriesCount();

        $this->postJson($this->updateUrl($person), [
            'expected_version' => 1,
            'full_name_ar' => ' موظف اختبار ',
            'gender_id' => $this->gender('male')->id,
            'marital_status_id' => $this->maritalStatus('married')->id,
            'birth_date' => '1990-05-17',
            'birth_place' => null,
        ])->assertOk()->assertJsonPath('version', 2);

        $this->assertSame(2, (int) DB::table('hr.persons')->where('id', $person->id)->value('version'), 'version increments exactly once');
        $this->assertSame($auditBefore + 1, $this->auditEntriesCount(), 'exactly one audit entry');
        $entry = $this->latestAuditEntryFor('hr.person.profile.update');
        $this->assertEquals(['changed_fields' => ['marital_status_id']], $entry->metadata);
        $this->assertEquals(['marital_status_id' => [
            'from' => $this->maritalStatus('single')->id,
            'to' => $this->maritalStatus('married')->id,
        ]], $entry->changes);
    }

    // ---------------------------------------------------------------------
    // Security (§S24.13)
    // ---------------------------------------------------------------------

    public function test_update_requires_the_explicit_update_profile_permission(): void
    {
        $person = $this->createPersonRecord();
        $url = "/api/v1/hr/persons/{$person->id}/update-profile";
        $payload = ['expected_version' => 1, 'full_name_ar' => 'اسم'];

        $this->postJson($url, $payload)->assertUnauthorized();

        $this->principalWithPermissions([Perm::PERSONS_VIEW, Perm::PERSONS_CREATE]);
        $this->postJson($url, $payload)->assertForbidden();

        $this->principalWithPermissions(ReferencePermissionCatalog::ALL);
        $this->postJson($url, $payload)->assertForbidden();
        $this->postJson('/api/v1/hr/persons', $this->personPayload())->assertForbidden();
        $this->getJson("/api/v1/hr/persons/{$person->id}")->assertForbidden();

        $this->principalWithPermissions([Perm::PERSONS_UPDATE_PROFILE]);
        $this->postJson($url, $payload)->assertOk();

        $this->assertSame('اسم', DB::table('hr.persons')->where('id', $person->id)->value('full_name_ar'));
    }

    public function test_reads_use_the_existing_person_view_permission(): void
    {
        $person = $this->createPersonRecord();

        $this->principalWithPermissions([Perm::PERSONS_VIEW]);
        $this->getJson("/api/v1/hr/persons/{$person->id}")->assertOk()->assertJsonPath('full_name_ar', 'موظف اختبار');

        $this->principalWithPermissions([Perm::PERSONS_UPDATE_PROFILE]);
        $this->getJson("/api/v1/hr/persons/{$person->id}")->assertForbidden();
    }

    public function test_the_update_profile_permission_is_seeded_in_the_hr_module(): void
    {
        $this->assertSame('human_resources', DB::table('security.permissions')->where('code', 'hr.persons.update_profile')->value('module'));
        $this->assertContains('hr.persons.update_profile', Perm::ALL);
    }

    // ---------------------------------------------------------------------
    // Audit (§S24.14)
    // ---------------------------------------------------------------------

    public function test_create_audit_records_reference_ids_and_field_names_but_no_pii_values(): void
    {
        $principal = $this->actingAsHrAdministrator();
        $payload = $this->personPayload(null, ['full_name_ar' => 'اسم سري تجريبي', 'birth_date' => '1977-03-09', 'birth_place' => 'مكان سري']);

        $id = $this->postJson('/api/v1/hr/persons', $payload)->assertStatus(201)->json('id');

        $entry = $this->latestAuditEntryFor('hr.person.create');
        $this->assertSame($principal->id, $entry->actor_principal_id);
        $this->assertSame('hr_person', $entry->target_type);
        $this->assertSame($id, $entry->target_id);
        $this->assertEquals(['gender_id' => $payload['gender_id'], 'marital_status_id' => $payload['marital_status_id']], $entry->changes);
        $this->assertEquals(['profile_fields_set' => self::PROFILE_COLUMNS], $entry->metadata);
        $this->assertNoPii($entry, ['اسم سري تجريبي', '1977-03-09', 'مكان سري', $payload['national_id']]);
    }

    public function test_update_audit_records_changed_field_names_and_old_new_reference_ids_only(): void
    {
        $principal = $this->actingAsHrAdministrator();
        $person = $this->createPersonRecord();
        $male = $this->gender('male')->id;
        $female = $this->gender('female')->id;

        $this->postJson("/api/v1/hr/persons/{$person->id}/update-profile", [
            'expected_version' => 1,
            'full_name_ar' => 'اسم سري معدل',
            'gender_id' => $female,
            'marital_status_id' => $this->maritalStatus('single')->id, // unchanged value
            'birth_place' => 'مكان سري معدل',
        ])->assertOk();

        $entry = $this->latestAuditEntryFor('hr.person.profile.update');
        $this->assertSame($principal->id, $entry->actor_principal_id);
        $this->assertSame('hr_person', $entry->target_type);
        $this->assertSame($person->id, $entry->target_id);
        $this->assertEquals(['gender_id' => ['from' => $male, 'to' => $female]], $entry->changes);
        $this->assertEquals(['changed_fields' => ['full_name_ar', 'gender_id', 'birth_place']], $entry->metadata);
        $this->assertNoPii($entry, ['اسم سري معدل', 'موظف اختبار', 'مكان سري معدل', '1990-05-17', $person->national_id]);
    }

    public function test_update_audit_for_a_legacy_person_records_null_as_the_old_reference(): void
    {
        $this->actingAsHrAdministrator();
        $legacy = $this->insertLegacyPerson();
        $single = $this->maritalStatus('single')->id;

        $this->postJson("/api/v1/hr/persons/{$legacy->id}/update-profile", ['expected_version' => 1, 'marital_status_id' => $single])->assertOk();

        $entry = $this->latestAuditEntryFor('hr.person.profile.update');
        $this->assertEquals(['marital_status_id' => ['from' => null, 'to' => $single]], $entry->changes);
        $this->assertEquals(['changed_fields' => ['marital_status_id']], $entry->metadata);
    }

    // ---------------------------------------------------------------------
    // Schema boundaries (§S24.6, §S24.15, §S24.18)
    // ---------------------------------------------------------------------

    public function test_schema_adds_no_age_name_part_history_start_work_specialty_or_experience_objects(): void
    {
        $columns = DB::table('information_schema.columns')->where('table_schema', 'hr')->where('table_name', 'persons')->pluck('column_name')->all();

        foreach (['age', 'first_name', 'father_name', 'grandfather_name', 'family_name', 'full_name_en', 'name_en', 'start_work_date',
            'hire_date', 'specialty_id', 'experience_years', 'birth_date_knowledge_state', 'birth_place_id'] as $forbidden) {
            $this->assertNotContains($forbidden, $columns, "ADR-S24-001: no {$forbidden} column");
        }

        $tables = DB::table('information_schema.tables')->where('table_schema', 'hr')->pluck('table_name')->all();
        foreach ($tables as $table) {
            $this->assertDoesNotMatchRegularExpression('/profile|name_part|gender|marital|birth|demograph/', $table, "no {$table} table in S24");
        }
        $this->assertSame(0, (int) DB::table('information_schema.tables')->where('table_schema', 'ref')
            ->where(fn ($q) => $q->where('table_name', 'like', '%place%')->orWhere('table_name', 'like', '%cit%')->orWhere('table_name', 'like', '%countr%'))
            ->count(), 'no geographic catalog');

        $this->assertSame('date', DB::table('information_schema.columns')->where('table_schema', 'hr')->where('table_name', 'persons')
            ->where('column_name', 'birth_date')->value('data_type'));
        foreach (self::PROFILE_COLUMNS as $column) {
            $this->assertSame('YES', DB::table('information_schema.columns')->where('table_schema', 'hr')->where('table_name', 'persons')
                ->where('column_name', $column)->value('is_nullable'), "{$column} is nullable at the database level (legacy)");
        }
    }

    public function test_there_is_no_generic_person_patch_or_put_route(): void
    {
        $this->actingAsHrAdministrator();
        $person = $this->createPersonRecord();

        $this->patchJson("/api/v1/hr/persons/{$person->id}", ['full_name_ar' => 'x'])->assertStatus(405);
        $this->putJson("/api/v1/hr/persons/{$person->id}", ['full_name_ar' => 'x'])->assertStatus(405);
    }

    // ---------------------------------------------------------------------
    // Reporting compatibility (§S24.16)
    // ---------------------------------------------------------------------

    public function test_age_is_derivable_at_query_time_and_unknowns_are_never_a_canonical_category(): void
    {
        $known = $this->createPersonRecord();
        $legacy = $this->insertLegacyPerson();

        $ages = DB::table('hr.persons')->whereIn('id', [$known->id, $legacy->id])
            ->selectRaw("id, extract(year from age(date '2026-05-17', birth_date))::int as age_at")->pluck('age_at', 'id');
        $this->assertSame(36, $ages[$known->id]);
        $this->assertNull($ages[$legacy->id], 'unknown birth date yields unknown age, never 0 or a default');

        $byGender = DB::table('hr.persons')->whereIn('hr.persons.id', [$known->id, $legacy->id])
            ->leftJoin('ref.genders', 'ref.genders.id', '=', 'hr.persons.gender_id')
            ->selectRaw('ref.genders.code as code, count(*) as n')->groupBy('ref.genders.code')->pluck('n', 'code');
        $this->assertSame(1, (int) $byGender['male']);
        $this->assertSame(1, (int) $byGender[''], 'the legacy row groups as NULL (unknown), not as male/female');
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    /** A pre-S24 Person: inserted raw with only the S09 identity columns, exactly as a migrated legacy row. */
    private function insertLegacyPerson(): Person
    {
        $id = (string) Str::uuid7();
        DB::table('hr.persons')->insert([
            'id' => $id,
            'national_id' => $this->uniqueNationalId(),
            'version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return Person::query()->findOrFail($id);
    }

    private function updateUrl(Person $person): string
    {
        return "/api/v1/hr/persons/{$person->id}/update-profile";
    }

    /** The whole persisted row, including version and updated_at — proves no UPDATE ran. */
    private function rowOf(Person $person): array
    {
        return (array) DB::table('hr.persons')->where('id', $person->id)->first();
    }

    /** @return array<string, ?string> */
    private function profileOf(Person $person): array
    {
        $row = DB::table('hr.persons')->where('id', $person->id)->first(self::PROFILE_COLUMNS);

        return (array) $row;
    }

    private function deactivateGender(string $code): void
    {
        $gender = $this->gender($code);
        app(DeactivateGender::class)->handle($gender, $gender->version);
    }

    private function deactivateMaritalStatus(string $code): void
    {
        $status = $this->maritalStatus($code);
        app(DeactivateMaritalStatus::class)->handle($status, $status->version);
    }

    private function assertNoPii(object $entry, array $values): void
    {
        $encoded = json_encode([$entry->changes, $entry->metadata], JSON_UNESCAPED_UNICODE);
        foreach ($values as $value) {
            $this->assertStringNotContainsString($value, $encoded, 'the audit payload must not carry profile PII values');
        }
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
