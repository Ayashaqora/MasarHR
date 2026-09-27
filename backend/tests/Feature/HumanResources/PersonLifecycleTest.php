<?php

namespace Tests\Feature\HumanResources;

use App\Modules\HumanResources\Application\Commands\CreatePerson;
use App\Modules\HumanResources\Application\Queries\FindPersonByNationalId;
use App\Modules\HumanResources\Domain\Exceptions\DuplicateNationalIdException;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\Person;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/** S09 Person lifecycle: identity, National-ID uniqueness/lookup, RBAC, audit, error shape (spec §4/§5/§19/§23). */
class PersonLifecycleTest extends HumanResourcesTestCase
{
    public function test_creating_a_person_persists_a_uuid_identity_and_trimmed_national_id(): void
    {
        $person = app(CreatePerson::class)->handle('  1234567890  ');

        $this->assertTrue(Str::isUuid($person->id));
        $this->assertSame('1234567890', $person->national_id);
        $this->assertFalse($person->is_terminal);
        $this->assertSame(1, $person->version);
    }

    public function test_a_duplicate_national_id_is_rejected_at_the_application_layer(): void
    {
        $nationalId = $this->uniqueNationalId();
        app(CreatePerson::class)->handle($nationalId);

        $this->expectException(DuplicateNationalIdException::class);
        app(CreatePerson::class)->handle($nationalId);
    }

    public function test_a_concurrent_duplicate_national_id_insert_is_rejected_by_the_database_not_just_the_application(): void
    {
        $nationalId = $this->uniqueNationalId();
        app(CreatePerson::class)->handle($nationalId);

        // Bypass the application command entirely: the UNIQUE constraint on national_id is what
        // actually prevents the race, not merely an application-level pre-check (mirrors
        // Tests\Feature\Security\PrincipalTest's identical proof for security.principals).
        $this->expectException(QueryException::class);
        DB::table('hr.persons')->insert([
            'id' => (string) Str::uuid7(),
            'national_id' => $nationalId,
            'is_terminal' => false,
            'version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_national_id_normalization_only_trims_whitespace(): void
    {
        $person = app(CreatePerson::class)->handle(' 5555555555 ');

        $this->assertSame('5555555555', $person->national_id);
    }

    public function test_lookup_finds_a_person_by_national_id(): void
    {
        $person = $this->createPersonRecord('4444444444');

        $found = app(FindPersonByNationalId::class)('4444444444');

        $this->assertNotNull($found);
        $this->assertSame($person->id, $found->id);
    }

    public function test_lookup_returns_null_for_an_unknown_national_id(): void
    {
        $this->assertNull(app(FindPersonByNationalId::class)('0000000000'));
    }

    public function test_lookup_trims_whitespace_before_matching(): void
    {
        $person = $this->createPersonRecord('3333333333');

        $found = app(FindPersonByNationalId::class)('  3333333333  ');

        $this->assertNotNull($found);
        $this->assertSame($person->id, $found->id);
    }

    public function test_creating_a_person_via_the_api_succeeds_and_is_audited(): void
    {
        $this->actingAsHrAdministrator();
        $nationalId = $this->uniqueNationalId();

        $response = $this->postJson('/api/v1/hr/persons', ['national_id' => $nationalId])->assertCreated();

        $response->assertJsonPath('national_id', $nationalId)->assertJsonPath('is_terminal', false);

        $entry = $this->latestAuditEntryFor('hr.person.create');
        $this->assertNotNull($entry);
        $this->assertSame($response->json('id'), $entry->target_id);
    }

    public function test_creating_a_duplicate_person_via_the_api_is_a_409(): void
    {
        $this->actingAsHrAdministrator();
        $nationalId = $this->uniqueNationalId();
        $this->createPersonRecord($nationalId);

        $this->postJson('/api/v1/hr/persons', ['national_id' => $nationalId])->assertStatus(409);
    }

    public function test_lookup_via_the_api_returns_the_matching_person_only(): void
    {
        $this->actingAsHrAdministrator();
        $target = $this->createPersonRecord('7777777777');
        $this->createPersonRecord('8888888888');

        $response = $this->getJson('/api/v1/hr/persons/lookup?national_id=7777777777')->assertOk();

        $response->assertJsonPath('id', $target->id)->assertJsonPath('national_id', '7777777777');
    }

    public function test_lookup_via_the_api_is_a_404_when_no_person_matches(): void
    {
        $this->actingAsHrAdministrator();

        $this->getJson('/api/v1/hr/persons/lookup?national_id=1231231231')->assertStatus(404);
    }

    public function test_show_via_the_api_returns_only_that_person_no_arbitrary_filtering(): void
    {
        $this->actingAsHrAdministrator();
        $person = $this->createPersonRecord();

        $this->getJson("/api/v1/hr/persons/{$person->id}")->assertOk()->assertJsonPath('id', $person->id);
    }

    public function test_show_of_an_unknown_person_is_a_404(): void
    {
        $this->actingAsHrAdministrator();

        $this->getJson('/api/v1/hr/persons/00000000-0000-0000-0000-000000000000')->assertStatus(404);
    }

    public function test_reads_are_rejected_without_authentication(): void
    {
        $person = $this->createPersonRecord();

        $this->getJson("/api/v1/hr/persons/{$person->id}")->assertStatus(401);
    }

    public function test_reads_are_rejected_without_the_persons_view_permission(): void
    {
        $principal = $this->createPrincipal();
        $this->actingAs($principal, 'web');
        $person = $this->createPersonRecord();

        $this->getJson("/api/v1/hr/persons/{$person->id}")->assertStatus(403);
    }

    public function test_writes_are_rejected_without_the_persons_create_permission(): void
    {
        $principal = $this->createPrincipal();
        $this->actingAs($principal, 'web');

        $this->postJson('/api/v1/hr/persons', ['national_id' => $this->uniqueNationalId()])->assertStatus(403);
    }

    public function test_error_responses_never_leak_sql_or_stack_traces(): void
    {
        $this->actingAsHrAdministrator();
        $nationalId = $this->uniqueNationalId();
        $this->createPersonRecord($nationalId);

        $body = $this->postJson('/api/v1/hr/persons', ['national_id' => $nationalId])->getContent();

        foreach (['SQLSTATE', '.php:', 'Stack trace', 'PDOException', 'select *'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $body);
        }
    }

    public function test_audit_payload_never_duplicates_national_id(): void
    {
        $this->actingAsHrAdministrator();
        $nationalId = $this->uniqueNationalId();

        $this->postJson('/api/v1/hr/persons', ['national_id' => $nationalId])->assertCreated();

        $entry = $this->latestAuditEntryFor('hr.person.create');
        $this->assertNotNull($entry);
        $payload = json_encode([$entry->changes, $entry->metadata]);
        $this->assertStringNotContainsString($nationalId, (string) $payload, 'national_id must not be duplicated into the audit payload');
    }

    public function test_no_hard_delete_route_exists_for_a_person(): void
    {
        foreach (glob(app_path('Modules/HumanResources/Application/Commands/*.php')) as $file) {
            $code = file_get_contents($file);
            $this->assertDoesNotMatchRegularExpression('/\$person->delete\s*\(|Person::destroy\s*\(/i', $code, "no person delete/destroy call in $file");
        }

        $hasDeleteRoute = collect(Route::getRoutes())
            ->contains(fn ($route) => str_contains($route->uri(), 'hr/persons') && in_array('DELETE', $route->methods(), true));
        $this->assertFalse($hasDeleteRoute, 'there must be no DELETE route anywhere under hr/persons');
    }
}
