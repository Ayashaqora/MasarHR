<?php

namespace Tests\Feature\HumanResources;

use App\Modules\HumanResources\Application\Commands\RecordEmploymentStatusPeriod;
use App\Modules\HumanResources\Domain\Exceptions\EmploymentRelationshipAlreadyEndedException;
use App\Modules\HumanResources\Domain\Exceptions\InvalidStatusPeriodDateException;
use App\Modules\HumanResources\Domain\Exceptions\PersonIsTerminalException;
use App\Modules\HumanResources\Domain\Exceptions\UnresolvedEmploymentStatusBehaviorException;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\EmploymentStatusPeriod;

/**
 * S10 Employment Status History: transitions, auto-close, relationship-ending/terminal
 * consequences, temporal integrity, RBAC, audit, error shape (spec §5-§9/§15/§20).
 */
class EmploymentStatusHistoryTest extends HumanResourcesTestCase
{
    public function test_the_first_status_period_leaves_an_active_relationship_open(): void
    {
        $person = $this->createPersonRecord();
        $relationship = $this->createEmploymentRelationship($person);

        $period = app(RecordEmploymentStatusPeriod::class)->handle(
            $person,
            $relationship,
            $this->statusDetail('on_duty'),
            '2026-09-27',
        );

        $this->assertSame($relationship->getKey(), $period->employment_relationship_id);
        $this->assertNull($period->effective_to);
        $this->assertSame('NOT_APPLICABLE', $relationship->refresh()->end_knowledge_state);
    }

    public function test_a_second_transition_closes_the_first_period_and_leaves_the_relationship_open(): void
    {
        $person = $this->createPersonRecord();
        $relationship = $this->createEmploymentRelationship($person);
        $command = app(RecordEmploymentStatusPeriod::class);

        $first = $command->handle($person, $relationship, $this->statusDetail('on_duty'), '2026-09-27');
        $second = $command->handle($person, $relationship, $this->statusDetail('traveling'), '2026-10-01');

        $this->assertSame('2026-10-01', $first->refresh()->effective_to->toDateString());
        $this->assertNull($second->effective_to);
        $this->assertSame('NOT_APPLICABLE', $relationship->refresh()->end_knowledge_state);
    }

    public function test_a_non_active_detail_does_not_end_the_relationship(): void
    {
        $person = $this->createPersonRecord();
        $relationship = $this->createEmploymentRelationship($person);

        app(RecordEmploymentStatusPeriod::class)->handle($person, $relationship, $this->statusDetail('unpaid_leave'), '2026-09-27');

        $relationship->refresh();
        $this->assertSame('NOT_APPLICABLE', $relationship->end_knowledge_state);
        $this->assertNull($relationship->effective_to);
    }

    public function test_an_ended_category_detail_closes_the_relationship_non_terminally(): void
    {
        $person = $this->createPersonRecord();
        $relationship = $this->createEmploymentRelationship($person);

        app(RecordEmploymentStatusPeriod::class)->handle($person, $relationship, $this->statusDetail('resigned'), '2026-09-27');

        $relationship->refresh();
        $this->assertSame('KNOWN', $relationship->end_knowledge_state);
        $this->assertSame('2026-09-27', $relationship->effective_to->toDateString());
        $this->assertFalse($relationship->ended_terminally);
        $this->assertFalse($person->refresh()->is_terminal);
    }

    public function test_reappointment_is_allowed_after_an_ended_category_transition(): void
    {
        $person = $this->createPersonRecord();
        $relationship = $this->createEmploymentRelationship($person);

        app(RecordEmploymentStatusPeriod::class)->handle($person, $relationship, $this->statusDetail('retired'), '2026-09-27');

        $new = $this->createEmploymentRelationship($person, 'contract', null, '2026-10-01');

        $this->assertSame($person->getKey(), $new->person_id);
        $this->assertNotSame($relationship->getKey(), $new->getKey());
    }

    public function test_a_terminal_category_detail_closes_the_relationship_and_blocks_reappointment(): void
    {
        $person = $this->createPersonRecord();
        $relationship = $this->createEmploymentRelationship($person);

        app(RecordEmploymentStatusPeriod::class)->handle($person, $relationship, $this->statusDetail('martyred'), '2026-09-27');

        $relationship->refresh();
        $this->assertSame('KNOWN', $relationship->end_knowledge_state);
        $this->assertTrue($relationship->ended_terminally);
        $this->assertTrue($person->refresh()->is_terminal);

        $this->expectException(PersonIsTerminalException::class);
        $this->createEmploymentRelationship($person, 'contract', null, '2026-10-01');
    }

    public function test_recording_a_period_against_an_already_ended_relationship_is_rejected(): void
    {
        $person = $this->createPersonRecord();
        $relationship = $this->createEmploymentRelationship($person);

        app(RecordEmploymentStatusPeriod::class)->handle($person, $relationship, $this->statusDetail('resigned'), '2026-09-27');

        $this->expectException(EmploymentRelationshipAlreadyEndedException::class);
        app(RecordEmploymentStatusPeriod::class)->handle($person, $relationship, $this->statusDetail('on_duty'), '2026-10-01');
    }

    public function test_a_status_effective_from_not_after_the_relationships_own_effective_from_is_rejected(): void
    {
        $person = $this->createPersonRecord();
        $relationship = $this->createEmploymentRelationship($person, 'permanent', null, '2026-09-27');

        $this->expectException(InvalidStatusPeriodDateException::class);
        app(RecordEmploymentStatusPeriod::class)->handle($person, $relationship, $this->statusDetail('on_duty'), '2026-09-27');
    }

    public function test_a_status_effective_from_not_after_the_currently_open_periods_effective_from_is_rejected(): void
    {
        $person = $this->createPersonRecord();
        $relationship = $this->createEmploymentRelationship($person);
        $command = app(RecordEmploymentStatusPeriod::class);

        $command->handle($person, $relationship, $this->statusDetail('on_duty'), '2026-10-01');

        $this->expectException(InvalidStatusPeriodDateException::class);
        $command->handle($person, $relationship, $this->statusDetail('traveling'), '2026-09-28');
    }

    public function test_an_unresolved_behavior_date_is_rejected_and_nothing_is_written(): void
    {
        $person = $this->createPersonRecord();
        $relationship = $this->createEmploymentRelationship($person);

        // 2026-06-01 is after the relationship's own 2026-01-01 effective_from, but before the
        // S06-seeded behavior periods' 2026-09-26 anchor — genuinely unresolved, not a temporal
        // ordering violation.
        try {
            app(RecordEmploymentStatusPeriod::class)->handle($person, $relationship, $this->statusDetail('on_duty'), '2026-06-01');
            $this->fail('Expected UnresolvedEmploymentStatusBehaviorException.');
        } catch (UnresolvedEmploymentStatusBehaviorException) {
            // expected
        }

        $this->assertSame(0, EmploymentStatusPeriod::query()->where('employment_relationship_id', $relationship->getKey())->count());
    }

    // --- API ---

    public function test_recording_a_status_period_via_the_api_returns_201(): void
    {
        $this->actingAsHrAdministrator();
        $person = $this->createPersonRecord();
        $relationship = $this->createEmploymentRelationship($person);

        $this->postJson(
            "/api/v1/hr/persons/{$person->id}/employment-relationships/{$relationship->id}/status-periods",
            ['status_detail_code' => 'on_duty', 'effective_from' => '2026-09-27'],
        )->assertStatus(201)->assertJsonPath('status_detail_id', fn ($id) => is_string($id));
    }

    public function test_listing_status_periods_via_the_api_orders_most_recent_first(): void
    {
        $this->actingAsHrAdministrator();
        $person = $this->createPersonRecord();
        $relationship = $this->createEmploymentRelationship($person);
        app(RecordEmploymentStatusPeriod::class)->handle($person, $relationship, $this->statusDetail('on_duty'), '2026-09-27');
        app(RecordEmploymentStatusPeriod::class)->handle($person, $relationship, $this->statusDetail('traveling'), '2026-10-01');

        $response = $this->getJson("/api/v1/hr/persons/{$person->id}/employment-relationships/{$relationship->id}/status-periods");

        // JsonResource::withoutWrapping() is enabled app-wide (AppServiceProvider), so a
        // collection response is a plain top-level array, not {"data": [...]}.
        $response->assertStatus(200);
        $this->assertSame('2026-10-01', $response->json('0.effective_from'));
        $this->assertSame('2026-09-27', $response->json('1.effective_from'));
    }

    public function test_recording_with_an_unknown_status_detail_code_is_a_404(): void
    {
        $this->actingAsHrAdministrator();
        $person = $this->createPersonRecord();
        $relationship = $this->createEmploymentRelationship($person);

        $this->postJson(
            "/api/v1/hr/persons/{$person->id}/employment-relationships/{$relationship->id}/status-periods",
            ['status_detail_code' => 'not-a-real-code', 'effective_from' => '2026-09-27'],
        )->assertStatus(404);
    }

    public function test_an_invalid_effective_from_via_the_api_is_a_422(): void
    {
        $this->actingAsHrAdministrator();
        $person = $this->createPersonRecord();
        $relationship = $this->createEmploymentRelationship($person, 'permanent', null, '2026-09-27');

        $this->postJson(
            "/api/v1/hr/persons/{$person->id}/employment-relationships/{$relationship->id}/status-periods",
            ['status_detail_code' => 'on_duty', 'effective_from' => '2026-09-27'],
        )->assertStatus(422)->assertJsonValidationErrors(['effective_from']);
    }

    public function test_missing_required_fields_via_the_api_is_a_422(): void
    {
        $this->actingAsHrAdministrator();
        $person = $this->createPersonRecord();
        $relationship = $this->createEmploymentRelationship($person);

        $this->postJson(
            "/api/v1/hr/persons/{$person->id}/employment-relationships/{$relationship->id}/status-periods",
            [],
        )->assertStatus(422)->assertJsonValidationErrors(['status_detail_code', 'effective_from']);
    }

    public function test_unauthenticated_requests_are_rejected(): void
    {
        $person = $this->createPersonRecord();
        $relationship = $this->createEmploymentRelationship($person);

        $this->getJson("/api/v1/hr/persons/{$person->id}/employment-relationships/{$relationship->id}/status-periods")
            ->assertStatus(401);
        $this->postJson(
            "/api/v1/hr/persons/{$person->id}/employment-relationships/{$relationship->id}/status-periods",
            ['status_detail_code' => 'on_duty', 'effective_from' => '2026-09-27'],
        )->assertStatus(401);
    }

    public function test_a_principal_without_the_permission_is_forbidden(): void
    {
        $principal = $this->createPrincipal();
        $this->actingAs($principal, 'web');
        $person = $this->createPersonRecord();
        $relationship = $this->createEmploymentRelationship($person);

        $this->postJson(
            "/api/v1/hr/persons/{$person->id}/employment-relationships/{$relationship->id}/status-periods",
            ['status_detail_code' => 'on_duty', 'effective_from' => '2026-09-27'],
        )->assertStatus(403);
    }

    public function test_a_relationship_not_belonging_to_the_given_person_is_a_404_idor_protection(): void
    {
        $this->actingAsHrAdministrator();
        $personA = $this->createPersonRecord();
        $personB = $this->createPersonRecord();
        $relationshipForB = $this->createEmploymentRelationship($personB);

        $this->postJson(
            "/api/v1/hr/persons/{$personA->id}/employment-relationships/{$relationshipForB->id}/status-periods",
            ['status_detail_code' => 'on_duty', 'effective_from' => '2026-09-27'],
        )->assertStatus(404);

        $this->getJson("/api/v1/hr/persons/{$personA->id}/employment-relationships/{$relationshipForB->id}/status-periods")
            ->assertStatus(404);
    }

    public function test_an_audit_entry_is_recorded_without_national_id(): void
    {
        $this->actingAsHrAdministrator();
        $person = $this->createPersonRecord('9998887770');
        $relationship = $this->createEmploymentRelationship($person);

        $this->postJson(
            "/api/v1/hr/persons/{$person->id}/employment-relationships/{$relationship->id}/status-periods",
            ['status_detail_code' => 'on_duty', 'effective_from' => '2026-09-27'],
        )->assertStatus(201);

        $entry = $this->latestAuditEntryFor('hr.employment_status_period.record');

        $this->assertNotNull($entry);
        $this->assertStringNotContainsString('9998887770', json_encode($entry->changes));
        $this->assertStringNotContainsString('9998887770', json_encode($entry->metadata));
    }

    public function test_an_audit_entry_records_a_non_terminal_relationship_closure_as_a_consequence(): void
    {
        // Adversarial-review finding (spec §14: "when triggered - that the relationship was also
        // closed as a consequence, plus whether terminally"). 'on_duty' never triggers a closure,
        // so the pre-existing audit test above never exercised this branch.
        $this->actingAsHrAdministrator();
        $person = $this->createPersonRecord();
        $relationship = $this->createEmploymentRelationship($person);

        $this->postJson(
            "/api/v1/hr/persons/{$person->id}/employment-relationships/{$relationship->id}/status-periods",
            ['status_detail_code' => 'resigned', 'effective_from' => '2026-09-27'],
        )->assertStatus(201);

        $entry = $this->latestAuditEntryFor('hr.employment_status_period.record');

        $this->assertNotNull($entry);
        $this->assertTrue($entry->metadata['relationship_closed_as_consequence']);
        $this->assertFalse($entry->metadata['ended_terminally']);
    }

    public function test_an_audit_entry_records_a_terminal_relationship_closure_as_a_consequence(): void
    {
        $this->actingAsHrAdministrator();
        $person = $this->createPersonRecord();
        $relationship = $this->createEmploymentRelationship($person);

        $this->postJson(
            "/api/v1/hr/persons/{$person->id}/employment-relationships/{$relationship->id}/status-periods",
            ['status_detail_code' => 'martyred', 'effective_from' => '2026-09-27'],
        )->assertStatus(201);

        $entry = $this->latestAuditEntryFor('hr.employment_status_period.record');

        $this->assertNotNull($entry);
        $this->assertTrue($entry->metadata['relationship_closed_as_consequence']);
        $this->assertTrue($entry->metadata['ended_terminally']);
    }

    public function test_an_audit_entry_for_a_non_ending_transition_carries_no_closure_flags(): void
    {
        $this->actingAsHrAdministrator();
        $person = $this->createPersonRecord();
        $relationship = $this->createEmploymentRelationship($person);

        $this->postJson(
            "/api/v1/hr/persons/{$person->id}/employment-relationships/{$relationship->id}/status-periods",
            ['status_detail_code' => 'on_duty', 'effective_from' => '2026-09-27'],
        )->assertStatus(201);

        $entry = $this->latestAuditEntryFor('hr.employment_status_period.record');

        $this->assertNotNull($entry);
        $this->assertArrayNotHasKey('relationship_closed_as_consequence', $entry->metadata);
        $this->assertArrayNotHasKey('ended_terminally', $entry->metadata);
    }

    public function test_a_principal_with_only_the_record_permission_cannot_list(): void
    {
        // Adversarial-review coverage gap: the existing 403 test above only exercised the .record
        // permission on POST; the .view permission on GET was never separately verified.
        $recordOnlyRole = $this->createRoleWithPermissions(['hr.employment_status_periods.record']);
        $principal = $this->createPrincipal();
        $this->assignRole($principal, $recordOnlyRole);
        $this->actingAs($principal, 'web');

        $person = $this->createPersonRecord();
        $relationship = $this->createEmploymentRelationship($person);

        $this->getJson("/api/v1/hr/persons/{$person->id}/employment-relationships/{$relationship->id}/status-periods")
            ->assertStatus(403);
    }

    public function test_no_patch_or_delete_route_exists_for_status_periods(): void
    {
        $person = $this->createPersonRecord();
        $relationship = $this->createEmploymentRelationship($person);
        $path = "/api/v1/hr/persons/{$person->id}/employment-relationships/{$relationship->id}/status-periods";

        $this->actingAsHrAdministrator();
        $this->patchJson($path, [])->assertStatus(405);
        $this->deleteJson($path)->assertStatus(405);
    }

    public function test_ending_the_relationship_directly_leaves_the_open_status_period_unreconciled_by_design(): void
    {
        // Disclosed deferred item (spec §18.1): S09's own EndEmploymentRelationship does not close
        // an open status period. No path in S09/S10 reaches this except a direct S09 `end` call
        // against a relationship that also has status history — verified here to be a known,
        // disclosed gap rather than an accidental one.
        $this->actingAsHrAdministrator();
        $person = $this->createPersonRecord();
        $relationship = $this->createEmploymentRelationship($person);
        $period = app(RecordEmploymentStatusPeriod::class)->handle($person, $relationship, $this->statusDetail('on_duty'), '2026-09-27');

        $this->postJson(
            "/api/v1/hr/persons/{$person->id}/employment-relationships/{$relationship->id}/end",
            ['expected_version' => $relationship->version, 'effective_to' => '2026-10-01', 'is_terminal' => false],
        )->assertStatus(200);

        $this->assertNull($period->refresh()->effective_to);
    }
}
