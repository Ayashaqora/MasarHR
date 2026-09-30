<?php

namespace Tests\Feature\HumanResources;

use App\Modules\HumanResources\Application\Commands\EndFullSecondment;
use App\Modules\HumanResources\Application\Commands\EndWorkplaceAssignment;
use App\Modules\HumanResources\Application\Commands\StartFullSecondment;
use App\Modules\HumanResources\Application\Commands\StartWorkplaceAssignment;
use App\Modules\HumanResources\Application\Queries\ResolveActualWorkplaceForRelationship;
use App\Modules\HumanResources\Domain\Exceptions\ActiveFullSecondmentAlreadyExistsException;
use App\Modules\HumanResources\Domain\Exceptions\EmploymentRelationshipAlreadyEndedException;
use App\Modules\HumanResources\Domain\Exceptions\InvalidWorkplaceAssignmentDecisionTypeException;
use App\Modules\HumanResources\Domain\Exceptions\InvalidWorkplaceAssignmentEndDateException;
use App\Modules\HumanResources\Domain\Exceptions\InvalidWorkplaceAssignmentStartDateException;
use App\Modules\HumanResources\Domain\Exceptions\NoActiveWorkplaceAssignmentException;
use App\Modules\HumanResources\Infrastructure\Authorization\HumanResourcesPermissionCatalog;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\OrganizationalPlacementPeriod;
use App\Modules\Organization\Application\Commands\DeactivateOrganizationalUnit;
use App\Modules\Reference\Infrastructure\Persistence\Eloquent\DecisionType;
use App\Modules\Security\Infrastructure\Persistence\Eloquent\Principal;
use Illuminate\Support\Str;

/**
 * S16 Workplace Assignment Foundation
 * (docs/workplace-assignment-foundation-specification.md): domain behaviour, API surface, the
 * §S16.14 dual-scope authorization rule, decision-type validation (§S16.12), and actual-workplace
 * resolution (§S16.8). Mirrors FullSecondmentFoundationTest's (S12) structural conventions
 * throughout, with the key behavioural differences §S16.9 documents: starting while one is
 * already active REPLACES it (never rejects), and starting is additionally rejected when an
 * active Full Secondment exists (§S16.8 mutual exclusion) — tested here for
 * StartWorkplaceAssignment's own side; StartFullSecondment's symmetric side is covered by
 * test_starting_a_full_secondment_while_an_assignment_is_active_is_rejected in
 * FullSecondmentFoundationTest-adjacent coverage below (this file, for locality with its own
 * fixture helpers).
 */
class WorkplaceAssignmentFoundationTest extends HumanResourcesTestCase
{
    // ---------------------------------------------------------------------
    // Domain / command behaviour
    // ---------------------------------------------------------------------

    public function test_starting_an_assignment_records_an_open_period(): void
    {
        $person = $this->createPersonRecord();
        $relationship = $this->createEmploymentRelationship($person);
        $destination = $this->createUnit();

        $period = app(StartWorkplaceAssignment::class)->handle($relationship, $destination, '2026-02-01', $this->assignmentDecisionType());

        $this->assertSame($relationship->id, $period->employment_relationship_id);
        $this->assertSame($destination->id, $period->organizational_unit_id);
        $this->assertNull($period->effective_to);
        $this->assertTrue($period->isActive());
    }

    /** Spec §S16.8/§S16.9: unlike S12, starting while one is active REPLACES it atomically. */
    public function test_starting_a_second_assignment_while_one_is_active_replaces_it(): void
    {
        $person = $this->createPersonRecord();
        $relationship = $this->createEmploymentRelationship($person);
        $destinationA = $this->createUnit();
        $destinationB = $this->createUnit();
        $decisionType = $this->assignmentDecisionType();

        $first = app(StartWorkplaceAssignment::class)->handle($relationship, $destinationA, '2026-02-01', $decisionType);
        $second = app(StartWorkplaceAssignment::class)->handle($relationship, $destinationB, '2026-03-01', $decisionType);

        $first->refresh();
        $this->assertSame('2026-03-01', $first->effective_to->toDateString(), 'the previous period must be closed at the new effective boundary');
        $this->assertFalse($first->isActive());
        $this->assertTrue($second->isActive());
        $this->assertSame($destinationB->id, $second->organizational_unit_id);

        // History is preserved — both rows still exist.
        $this->assertDatabaseCount('hr.workplace_assignment_periods', 2);
    }

    public function test_replacing_with_an_effective_from_not_strictly_after_the_open_periods_own_effective_from_is_rejected(): void
    {
        $person = $this->createPersonRecord();
        $relationship = $this->createEmploymentRelationship($person);
        $decisionType = $this->assignmentDecisionType();
        app(StartWorkplaceAssignment::class)->handle($relationship, $this->createUnit(), '2026-02-01', $decisionType);

        $this->expectException(InvalidWorkplaceAssignmentStartDateException::class);

        app(StartWorkplaceAssignment::class)->handle($relationship, $this->createUnit(), '2026-02-01', $decisionType);
    }

    public function test_ending_an_assignment_closes_the_open_period(): void
    {
        $person = $this->createPersonRecord();
        $relationship = $this->createEmploymentRelationship($person);
        $destination = $this->createUnit();

        app(StartWorkplaceAssignment::class)->handle($relationship, $destination, '2026-02-01', $this->assignmentDecisionType());
        $ended = app(EndWorkplaceAssignment::class)->handle($relationship, '2026-03-01');

        $this->assertSame('2026-03-01', $ended->effective_to->toDateString());
        $this->assertFalse($ended->isActive());
    }

    public function test_starting_after_ending_a_previous_assignment_is_allowed(): void
    {
        $person = $this->createPersonRecord();
        $relationship = $this->createEmploymentRelationship($person);
        $destinationA = $this->createUnit();
        $destinationB = $this->createUnit();
        $decisionType = $this->assignmentDecisionType();

        app(StartWorkplaceAssignment::class)->handle($relationship, $destinationA, '2026-02-01', $decisionType);
        app(EndWorkplaceAssignment::class)->handle($relationship, '2026-03-01');

        $second = app(StartWorkplaceAssignment::class)->handle($relationship, $destinationB, '2026-04-01', $decisionType);

        $this->assertTrue($second->isActive());
        $this->assertSame($destinationB->id, $second->organizational_unit_id);
    }

    public function test_starting_against_an_already_ended_relationship_is_rejected(): void
    {
        $person = $this->createPersonRecord();
        $relationship = $this->createEmploymentRelationship($person, effectiveFrom: '2026-01-01');
        $relationship->forceFill(['effective_to' => '2026-02-01', 'end_knowledge_state' => 'KNOWN'])->save();

        $this->expectException(EmploymentRelationshipAlreadyEndedException::class);

        app(StartWorkplaceAssignment::class)->handle($relationship, $this->createUnit(), '2026-03-01', $this->assignmentDecisionType());
    }

    /**
     * S35: the End command is now rejected once the owning relationship has ended on/before the requested
     * end date (previously "administrative cleanup" was allowed). The relationship end itself truncates
     * such rows, so this state is only reachable for legacy data; the row must not be mutated.
     */
    public function test_ending_against_an_already_ended_relationship_is_rejected_and_leaves_the_row_untouched(): void
    {
        $person = $this->createPersonRecord();
        $relationship = $this->createEmploymentRelationship($person);
        $destination = $this->createUnit();

        $period = app(StartWorkplaceAssignment::class)->handle($relationship, $destination, '2026-02-01', $this->assignmentDecisionType());

        $relationship->forceFill(['effective_to' => '2026-03-01', 'end_knowledge_state' => 'KNOWN'])->save();

        try {
            app(EndWorkplaceAssignment::class)->handle($relationship, '2026-03-15');
            $this->fail('an ended relationship must reject the end command');
        } catch (EmploymentRelationshipAlreadyEndedException) {
            $this->assertNull($period->refresh()->effective_to);
        }
    }

    public function test_starting_with_a_backdated_effective_from_is_rejected(): void
    {
        $person = $this->createPersonRecord();
        $relationship = $this->createEmploymentRelationship($person, effectiveFrom: '2026-01-01');

        $this->expectException(InvalidWorkplaceAssignmentStartDateException::class);

        app(StartWorkplaceAssignment::class)->handle($relationship, $this->createUnit(), '2026-01-01', $this->assignmentDecisionType());
    }

    public function test_ending_with_a_backdated_effective_to_is_rejected(): void
    {
        $person = $this->createPersonRecord();
        $relationship = $this->createEmploymentRelationship($person);
        $destination = $this->createUnit();

        app(StartWorkplaceAssignment::class)->handle($relationship, $destination, '2026-02-01', $this->assignmentDecisionType());

        $this->expectException(InvalidWorkplaceAssignmentEndDateException::class);

        app(EndWorkplaceAssignment::class)->handle($relationship, '2026-02-01');
    }

    public function test_ending_when_none_active_is_rejected(): void
    {
        $person = $this->createPersonRecord();
        $relationship = $this->createEmploymentRelationship($person);

        $this->expectException(NoActiveWorkplaceAssignmentException::class);

        app(EndWorkplaceAssignment::class)->handle($relationship, '2026-02-01');
    }

    public function test_starting_never_writes_to_the_placement_stream(): void
    {
        $person = $this->createPersonRecord();
        $relationship = $this->createEmploymentRelationship($person);
        $placementUnit = $this->createUnit();
        $this->recordPlacement($relationship, $placementUnit, '2026-01-15');

        app(StartWorkplaceAssignment::class)->handle($relationship, $this->createUnit(), '2026-02-01', $this->assignmentDecisionType());

        // The S11 placement stream is untouched — still the original unit, no new row inserted.
        $current = OrganizationalPlacementPeriod::query()
            ->where('employment_relationship_id', $relationship->id)
            ->whereNull('effective_to')
            ->first();
        $this->assertSame($placementUnit->id, $current->organizational_unit_id);
    }

    // ---------------------------------------------------------------------
    // Decision-type validation (spec §S16.12)
    // ---------------------------------------------------------------------

    public function test_starting_with_the_wrong_decision_type_code_is_rejected(): void
    {
        $person = $this->createPersonRecord();
        $relationship = $this->createEmploymentRelationship($person);

        $this->expectException(InvalidWorkplaceAssignmentDecisionTypeException::class);

        // TRANSFER, not ASSIGNMENT — the stable discriminator is code, never display text.
        app(StartWorkplaceAssignment::class)->handle($relationship, $this->createUnit(), '2026-02-01', $this->transferDecisionType());
    }

    public function test_starting_with_an_inactive_decision_type_is_rejected(): void
    {
        $person = $this->createPersonRecord();
        $relationship = $this->createEmploymentRelationship($person);
        $decisionType = $this->assignmentDecisionType();
        $decisionType->forceFill(['is_active' => false, 'version' => $decisionType->version + 1])->save();

        $this->expectException(InvalidWorkplaceAssignmentDecisionTypeException::class);

        app(StartWorkplaceAssignment::class)->handle($relationship, $this->createUnit(), '2026-02-01', $decisionType);
    }

    public function test_no_assignment_subtype_was_seeded(): void
    {
        $forbiddenCodes = ['ASSIGNMENT_TEMPORARY', 'ASSIGNMENT_PERMANENT', 'ASSIGNMENT_INTERNAL', 'ASSIGNMENT_EXTERNAL'];

        $this->assertSame(0, DecisionType::query()->whereIn('code', $forbiddenCodes)->count());
        $this->assertSame(1, DecisionType::query()->where('code', 'like', 'ASSIGNMENT%')->count());
    }

    // ---------------------------------------------------------------------
    // Mutual exclusion with Full Secondment (spec §S16.8 interaction matrix)
    // ---------------------------------------------------------------------

    /**
     * S28 (ADR-S28-001) supersedes the S16 §S16.8 cross-stream REJECT: the new assignment ends the
     * secondment effective at its start date instead of being refused. Full coverage lives in
     * MovementTemporalIntegrityCorrectiveTest.
     */
    public function test_starting_an_assignment_while_a_full_secondment_is_active_supersedes_it(): void
    {
        $person = $this->createPersonRecord();
        $relationship = $this->createEmploymentRelationship($person);
        $secondment = app(StartFullSecondment::class)->handle($relationship, $this->createUnit(), '2026-02-01');

        $assignment = app(StartWorkplaceAssignment::class)->handle($relationship, $this->createUnit(), '2026-03-01', $this->assignmentDecisionType());

        $this->assertSame('2026-03-01', $secondment->refresh()->effective_to->toDateString());
        $this->assertTrue($assignment->isActive());
    }

    /** S28 (ADR-S28-001): the reverse direction — the new secondment ends the assignment. */
    public function test_starting_a_full_secondment_while_an_assignment_is_active_supersedes_it(): void
    {
        $person = $this->createPersonRecord();
        $relationship = $this->createEmploymentRelationship($person);
        $assignment = app(StartWorkplaceAssignment::class)->handle($relationship, $this->createUnit(), '2026-02-01', $this->assignmentDecisionType());

        $secondment = app(StartFullSecondment::class)->handle($relationship, $this->createUnit(), '2026-03-01');

        $this->assertSame('2026-03-01', $assignment->refresh()->effective_to->toDateString());
        $this->assertNull($secondment->effective_to);
    }

    /** S28: later-recorded history is never rewritten — the unchanged 409 contract still applies. */
    public function test_a_movement_starting_before_a_later_recorded_cross_stream_movement_is_rejected(): void
    {
        $person = $this->createPersonRecord();
        $relationship = $this->createEmploymentRelationship($person);
        app(StartFullSecondment::class)->handle($relationship, $this->createUnit(), '2026-05-01');

        $this->expectException(ActiveFullSecondmentAlreadyExistsException::class);

        app(StartWorkplaceAssignment::class)->handle($relationship, $this->createUnit(), '2026-03-01', $this->assignmentDecisionType());
    }

    public function test_starting_an_assignment_after_a_secondment_has_ended_is_allowed(): void
    {
        $person = $this->createPersonRecord();
        $relationship = $this->createEmploymentRelationship($person);
        app(StartFullSecondment::class)->handle($relationship, $this->createUnit(), '2026-02-01');
        app(EndFullSecondment::class)->handle($relationship, '2026-03-01');

        $assignment = app(StartWorkplaceAssignment::class)->handle($relationship, $this->createUnit(), '2026-03-15', $this->assignmentDecisionType());

        $this->assertTrue($assignment->isActive());
    }

    // ---------------------------------------------------------------------
    // Actual workplace resolution (spec §S16.8)
    // ---------------------------------------------------------------------

    public function test_actual_workplace_resolves_to_assignment_when_active(): void
    {
        $person = $this->createPersonRecord();
        $relationship = $this->createEmploymentRelationship($person);
        $placementUnit = $this->createUnit();
        $assignmentUnit = $this->createUnit();
        $this->recordPlacement($relationship, $placementUnit, '2026-01-15');
        app(StartWorkplaceAssignment::class)->handle($relationship, $assignmentUnit, '2026-02-01', $this->assignmentDecisionType());

        $workplace = app(ResolveActualWorkplaceForRelationship::class)($relationship);

        $this->assertSame($assignmentUnit->id, $workplace->organizationalUnitId());
        $this->assertSame('assignment', $workplace->source());
    }

    public function test_actual_workplace_reverts_to_placement_after_assignment_ends(): void
    {
        $person = $this->createPersonRecord();
        $relationship = $this->createEmploymentRelationship($person);
        $placementUnit = $this->createUnit();
        $assignmentUnit = $this->createUnit();
        $this->recordPlacement($relationship, $placementUnit, '2026-01-15');
        app(StartWorkplaceAssignment::class)->handle($relationship, $assignmentUnit, '2026-02-01', $this->assignmentDecisionType());
        app(EndWorkplaceAssignment::class)->handle($relationship, '2026-03-01');

        $workplace = app(ResolveActualWorkplaceForRelationship::class)($relationship);

        $this->assertSame($placementUnit->id, $workplace->organizationalUnitId());
        $this->assertSame('placement', $workplace->source());
    }

    public function test_actual_workplace_is_unresolved_once_relationship_has_ended_even_with_an_open_assignment(): void
    {
        $person = $this->createPersonRecord();
        $relationship = $this->createEmploymentRelationship($person);
        $assignmentUnit = $this->createUnit();
        app(StartWorkplaceAssignment::class)->handle($relationship, $assignmentUnit, '2026-02-01', $this->assignmentDecisionType());

        // The relationship row is put directly into an ended state with the assignment still open
        // (bypassing EndEmploymentRelationship entirely) so this test isolates
        // ResolveActualWorkplaceForRelationship's own read-time neutralisation, mirroring
        // FullSecondmentFoundationTest's identical test for secondment.
        $relationship->forceFill(['effective_to' => '2026-03-01', 'end_knowledge_state' => 'KNOWN'])->save();

        $workplace = app(ResolveActualWorkplaceForRelationship::class)($relationship);

        $this->assertFalse($workplace->isResolved());
    }

    // ---------------------------------------------------------------------
    // API
    // ---------------------------------------------------------------------

    public function test_store_returns_201_and_the_created_period(): void
    {
        $this->actingAsHrAdministrator();
        $person = $this->createPersonRecord();
        $relationship = $this->createEmploymentRelationship($person);
        $destination = $this->createUnit();

        $response = $this->postJson(
            "/api/v1/hr/persons/{$person->id}/employment-relationships/{$relationship->id}/workplace-assignment-periods",
            ['organizational_unit_id' => $destination->id, 'effective_from' => '2026-02-01', 'decision_type_id' => $this->assignmentDecisionType()->id],
        );

        $response->assertStatus(201)
            ->assertJsonPath('organizational_unit_id', $destination->id)
            ->assertJsonPath('effective_to', null);
    }

    public function test_index_lists_periods_most_recent_first(): void
    {
        $this->actingAsHrAdministrator();
        $person = $this->createPersonRecord();
        $relationship = $this->createEmploymentRelationship($person);
        $decisionType = $this->assignmentDecisionType();

        app(StartWorkplaceAssignment::class)->handle($relationship, $this->createUnit(), '2026-02-01', $decisionType);
        app(EndWorkplaceAssignment::class)->handle($relationship, '2026-03-01');
        app(StartWorkplaceAssignment::class)->handle($relationship, $this->createUnit(), '2026-04-01', $decisionType);

        $response = $this->getJson(
            "/api/v1/hr/persons/{$person->id}/employment-relationships/{$relationship->id}/workplace-assignment-periods",
        )->assertOk();

        $this->assertSame('2026-04-01', $response->json('0.effective_from'));
        $this->assertSame('2026-02-01', $response->json('1.effective_from'));
    }

    public function test_end_returns_200_and_the_closed_period(): void
    {
        $this->actingAsHrAdministrator();
        $person = $this->createPersonRecord();
        $relationship = $this->createEmploymentRelationship($person);
        app(StartWorkplaceAssignment::class)->handle($relationship, $this->createUnit(), '2026-02-01', $this->assignmentDecisionType());

        $response = $this->postJson(
            "/api/v1/hr/persons/{$person->id}/employment-relationships/{$relationship->id}/workplace-assignment-periods/end",
            ['effective_to' => '2026-03-01'],
        );

        $response->assertOk()->assertJsonPath('effective_to', '2026-03-01');
    }

    public function test_actual_workplace_endpoint_reports_an_active_assignment(): void
    {
        $this->actingAsHrAdministrator();
        $person = $this->createPersonRecord();
        $relationship = $this->createEmploymentRelationship($person);
        $unit = $this->createUnit();
        app(StartWorkplaceAssignment::class)->handle($relationship, $unit, '2026-02-01', $this->assignmentDecisionType());

        $this->getJson(
            "/api/v1/hr/persons/{$person->id}/employment-relationships/{$relationship->id}/actual-workplace",
        )->assertOk()->assertJsonPath('organizational_unit_id', $unit->id)->assertJsonPath('source', 'assignment');
    }

    public function test_store_with_unknown_organizational_unit_is_404(): void
    {
        $this->actingAsHrAdministrator();
        $person = $this->createPersonRecord();
        $relationship = $this->createEmploymentRelationship($person);

        $this->postJson(
            "/api/v1/hr/persons/{$person->id}/employment-relationships/{$relationship->id}/workplace-assignment-periods",
            ['organizational_unit_id' => (string) Str::uuid7(), 'effective_from' => '2026-02-01', 'decision_type_id' => $this->assignmentDecisionType()->id],
        )->assertNotFound();
    }

    public function test_store_with_unknown_decision_type_is_404(): void
    {
        $this->actingAsHrAdministrator();
        $person = $this->createPersonRecord();
        $relationship = $this->createEmploymentRelationship($person);

        $this->postJson(
            "/api/v1/hr/persons/{$person->id}/employment-relationships/{$relationship->id}/workplace-assignment-periods",
            ['organizational_unit_id' => $this->createUnit()->id, 'effective_from' => '2026-02-01', 'decision_type_id' => (string) Str::uuid7()],
        )->assertNotFound();
    }

    public function test_store_with_the_wrong_decision_type_is_422(): void
    {
        $this->actingAsHrAdministrator();
        $person = $this->createPersonRecord();
        $relationship = $this->createEmploymentRelationship($person);

        $this->postJson(
            "/api/v1/hr/persons/{$person->id}/employment-relationships/{$relationship->id}/workplace-assignment-periods",
            ['organizational_unit_id' => $this->createUnit()->id, 'effective_from' => '2026-02-01', 'decision_type_id' => $this->transferDecisionType()->id],
        )->assertStatus(422)->assertJsonValidationErrors('decision_type_id');
    }

    public function test_store_missing_fields_is_422(): void
    {
        $this->actingAsHrAdministrator();
        $person = $this->createPersonRecord();
        $relationship = $this->createEmploymentRelationship($person);

        $this->postJson(
            "/api/v1/hr/persons/{$person->id}/employment-relationships/{$relationship->id}/workplace-assignment-periods",
            [],
        )->assertStatus(422);
    }

    public function test_end_missing_fields_is_422(): void
    {
        $this->actingAsHrAdministrator();
        $person = $this->createPersonRecord();
        $relationship = $this->createEmploymentRelationship($person);
        app(StartWorkplaceAssignment::class)->handle($relationship, $this->createUnit(), '2026-02-01', $this->assignmentDecisionType());

        $this->postJson(
            "/api/v1/hr/persons/{$person->id}/employment-relationships/{$relationship->id}/workplace-assignment-periods/end",
            [],
        )->assertStatus(422);
    }

    public function test_unauthenticated_requests_are_401(): void
    {
        $person = $this->createPersonRecord();
        $relationship = $this->createEmploymentRelationship($person);

        $this->getJson("/api/v1/hr/persons/{$person->id}/employment-relationships/{$relationship->id}/workplace-assignment-periods")->assertUnauthorized();
        $this->postJson("/api/v1/hr/persons/{$person->id}/employment-relationships/{$relationship->id}/workplace-assignment-periods", [])->assertUnauthorized();
        $this->postJson("/api/v1/hr/persons/{$person->id}/employment-relationships/{$relationship->id}/workplace-assignment-periods/end", [])->assertUnauthorized();
    }

    public function test_a_relationship_not_belonging_to_the_given_person_is_a_404_idor_protection(): void
    {
        $this->actingAsHrAdministrator();
        $personA = $this->createPersonRecord();
        $personB = $this->createPersonRecord();
        $relationshipOfB = $this->createEmploymentRelationship($personB);

        $this->getJson(
            "/api/v1/hr/persons/{$personA->id}/employment-relationships/{$relationshipOfB->id}/workplace-assignment-periods",
        )->assertNotFound();
    }

    public function test_no_patch_or_delete_route_exists_for_workplace_assignment_periods(): void
    {
        $this->actingAsHrAdministrator();
        $person = $this->createPersonRecord();
        $relationship = $this->createEmploymentRelationship($person);

        $this->patchJson("/api/v1/hr/persons/{$person->id}/employment-relationships/{$relationship->id}/workplace-assignment-periods")->assertStatus(405);
        $this->deleteJson("/api/v1/hr/persons/{$person->id}/employment-relationships/{$relationship->id}/workplace-assignment-periods")->assertStatus(405);
    }

    // ---------------------------------------------------------------------
    // Authorization / dual-scope matrix (spec §S16.14)
    // ---------------------------------------------------------------------

    public function test_starting_without_any_scope_grant_is_forbidden(): void
    {
        $this->principalWithPermissions([HumanResourcesPermissionCatalog::WORKPLACE_ASSIGNMENT_PERIODS_START]);
        $person = $this->createPersonRecord();
        $relationship = $this->createEmploymentRelationship($person);
        $destination = $this->createUnit();

        $this->postJson(
            "/api/v1/hr/persons/{$person->id}/employment-relationships/{$relationship->id}/workplace-assignment-periods",
            ['organizational_unit_id' => $destination->id, 'effective_from' => '2026-02-01', 'decision_type_id' => $this->assignmentDecisionType()->id],
        )->assertForbidden();
    }

    public function test_starting_with_scope_over_destination_only_is_forbidden_when_source_is_out_of_scope(): void
    {
        $principal = $this->principalWithPermissions([HumanResourcesPermissionCatalog::WORKPLACE_ASSIGNMENT_PERIODS_START]);
        $person = $this->createPersonRecord();
        $relationship = $this->createEmploymentRelationship($person);
        $sourceUnit = $this->createUnit();
        $destinationUnit = $this->createUnit();
        $this->recordPlacement($relationship, $sourceUnit, '2026-01-15');

        $this->grantUnitScope($principal, $destinationUnit);

        $this->postJson(
            "/api/v1/hr/persons/{$person->id}/employment-relationships/{$relationship->id}/workplace-assignment-periods",
            ['organizational_unit_id' => $destinationUnit->id, 'effective_from' => '2026-02-01', 'decision_type_id' => $this->assignmentDecisionType()->id],
        )->assertForbidden();

        $this->assertDatabaseCount('hr.workplace_assignment_periods', 0);
    }

    public function test_starting_with_scope_over_source_only_is_forbidden_when_destination_is_out_of_scope(): void
    {
        $principal = $this->principalWithPermissions([HumanResourcesPermissionCatalog::WORKPLACE_ASSIGNMENT_PERIODS_START]);
        $person = $this->createPersonRecord();
        $relationship = $this->createEmploymentRelationship($person);
        $sourceUnit = $this->createUnit();
        $destinationUnit = $this->createUnit();
        $this->recordPlacement($relationship, $sourceUnit, '2026-01-15');

        $this->grantUnitScope($principal, $sourceUnit);

        $this->postJson(
            "/api/v1/hr/persons/{$person->id}/employment-relationships/{$relationship->id}/workplace-assignment-periods",
            ['organizational_unit_id' => $destinationUnit->id, 'effective_from' => '2026-02-01', 'decision_type_id' => $this->assignmentDecisionType()->id],
        )->assertForbidden();
    }

    public function test_starting_with_scope_over_both_source_and_destination_is_allowed(): void
    {
        $principal = $this->principalWithPermissions([HumanResourcesPermissionCatalog::WORKPLACE_ASSIGNMENT_PERIODS_START]);
        $person = $this->createPersonRecord();
        $relationship = $this->createEmploymentRelationship($person);
        $sourceUnit = $this->createUnit();
        $destinationUnit = $this->createUnit();
        $this->recordPlacement($relationship, $sourceUnit, '2026-01-15');

        $this->grantUnitScope($principal, $sourceUnit);
        $this->grantUnitScope($principal, $destinationUnit);

        $this->postJson(
            "/api/v1/hr/persons/{$person->id}/employment-relationships/{$relationship->id}/workplace-assignment-periods",
            ['organizational_unit_id' => $destinationUnit->id, 'effective_from' => '2026-02-01', 'decision_type_id' => $this->assignmentDecisionType()->id],
        )->assertStatus(201);
    }

    public function test_starting_with_global_scope_is_allowed_for_any_unit(): void
    {
        $principal = $this->principalWithPermissions([HumanResourcesPermissionCatalog::WORKPLACE_ASSIGNMENT_PERIODS_START]);
        $person = $this->createPersonRecord();
        $relationship = $this->createEmploymentRelationship($person);
        $sourceUnit = $this->createUnit();
        $destinationUnit = $this->createUnit();
        $this->recordPlacement($relationship, $sourceUnit, '2026-01-15');

        $this->grantGlobalScope($principal);

        $this->postJson(
            "/api/v1/hr/persons/{$person->id}/employment-relationships/{$relationship->id}/workplace-assignment-periods",
            ['organizational_unit_id' => $destinationUnit->id, 'effective_from' => '2026-02-01', 'decision_type_id' => $this->assignmentDecisionType()->id],
        )->assertStatus(201);
    }

    public function test_starting_into_an_inactive_destination_is_forbidden_even_with_global_scope(): void
    {
        $principal = $this->principalWithPermissions([HumanResourcesPermissionCatalog::WORKPLACE_ASSIGNMENT_PERIODS_START]);
        $this->grantGlobalScope($principal);
        $person = $this->createPersonRecord();
        $relationship = $this->createEmploymentRelationship($person);
        $destination = $this->createUnit();
        app(DeactivateOrganizationalUnit::class)->handle($destination, $destination->version);

        $this->postJson(
            "/api/v1/hr/persons/{$person->id}/employment-relationships/{$relationship->id}/workplace-assignment-periods",
            ['organizational_unit_id' => $destination->id, 'effective_from' => '2026-02-01', 'decision_type_id' => $this->assignmentDecisionType()->id],
        )->assertForbidden();
    }

    public function test_starting_with_no_placement_yet_only_checks_destination_scope(): void
    {
        $principal = $this->principalWithPermissions([HumanResourcesPermissionCatalog::WORKPLACE_ASSIGNMENT_PERIODS_START]);
        $person = $this->createPersonRecord();
        $relationship = $this->createEmploymentRelationship($person);
        $destinationUnit = $this->createUnit();

        // No placement recorded at all — nothing to check scope against on the source side.
        $this->grantUnitScope($principal, $destinationUnit);

        $this->postJson(
            "/api/v1/hr/persons/{$person->id}/employment-relationships/{$relationship->id}/workplace-assignment-periods",
            ['organizational_unit_id' => $destinationUnit->id, 'effective_from' => '2026-02-01', 'decision_type_id' => $this->assignmentDecisionType()->id],
        )->assertStatus(201);
    }

    public function test_ending_without_scope_over_the_assignment_unit_is_forbidden(): void
    {
        $principal = $this->principalWithPermissions([
            HumanResourcesPermissionCatalog::WORKPLACE_ASSIGNMENT_PERIODS_START,
            HumanResourcesPermissionCatalog::WORKPLACE_ASSIGNMENT_PERIODS_END,
        ]);
        $this->grantGlobalScope($principal);
        $person = $this->createPersonRecord();
        $relationship = $this->createEmploymentRelationship($person);
        $assignmentUnit = $this->createUnit();

        $this->postJson(
            "/api/v1/hr/persons/{$person->id}/employment-relationships/{$relationship->id}/workplace-assignment-periods",
            ['organizational_unit_id' => $assignmentUnit->id, 'effective_from' => '2026-02-01', 'decision_type_id' => $this->assignmentDecisionType()->id],
        )->assertStatus(201);

        $endPrincipal = $this->principalWithPermissions([HumanResourcesPermissionCatalog::WORKPLACE_ASSIGNMENT_PERIODS_END]);

        $this->postJson(
            "/api/v1/hr/persons/{$person->id}/employment-relationships/{$relationship->id}/workplace-assignment-periods/end",
            ['effective_to' => '2026-03-01'],
        )->assertForbidden();
    }

    public function test_ending_with_scope_over_both_units_is_allowed(): void
    {
        $principal = $this->principalWithPermissions([
            HumanResourcesPermissionCatalog::WORKPLACE_ASSIGNMENT_PERIODS_START,
            HumanResourcesPermissionCatalog::WORKPLACE_ASSIGNMENT_PERIODS_END,
        ]);
        $person = $this->createPersonRecord();
        $relationship = $this->createEmploymentRelationship($person);
        $sourceUnit = $this->createUnit();
        $assignmentUnit = $this->createUnit();
        $this->recordPlacement($relationship, $sourceUnit, '2026-01-15');
        $this->grantUnitScope($principal, $sourceUnit);
        $this->grantUnitScope($principal, $assignmentUnit);

        $this->postJson(
            "/api/v1/hr/persons/{$person->id}/employment-relationships/{$relationship->id}/workplace-assignment-periods",
            ['organizational_unit_id' => $assignmentUnit->id, 'effective_from' => '2026-02-01', 'decision_type_id' => $this->assignmentDecisionType()->id],
        )->assertStatus(201);

        $this->postJson(
            "/api/v1/hr/persons/{$person->id}/employment-relationships/{$relationship->id}/workplace-assignment-periods/end",
            ['effective_to' => '2026-03-01'],
        )->assertOk();
    }

    public function test_ending_with_none_active_is_allowed_on_permission_alone_and_then_conflicts(): void
    {
        $this->principalWithPermissions([HumanResourcesPermissionCatalog::WORKPLACE_ASSIGNMENT_PERIODS_END]);
        $person = $this->createPersonRecord();
        $relationship = $this->createEmploymentRelationship($person);

        // No scope grant at all, and nothing active to end — the scope check has no unit to
        // check, so the request reaches the command, which supplies the substantive 409.
        $this->postJson(
            "/api/v1/hr/persons/{$person->id}/employment-relationships/{$relationship->id}/workplace-assignment-periods/end",
            ['effective_to' => '2026-03-01'],
        )->assertStatus(409);
    }

    public function test_reading_index_is_denied_when_the_resolved_actual_workplace_unit_is_out_of_scope(): void
    {
        $principal = $this->principalWithPermissions([HumanResourcesPermissionCatalog::WORKPLACE_ASSIGNMENT_PERIODS_VIEW]);
        $person = $this->createPersonRecord();
        $relationship = $this->createEmploymentRelationship($person);
        $unit = $this->createUnit();
        $this->recordPlacement($relationship, $unit, '2026-01-15');

        $otherUnit = $this->createUnit();
        $this->grantUnitScope($principal, $otherUnit);

        $this->getJson(
            "/api/v1/hr/persons/{$person->id}/employment-relationships/{$relationship->id}/workplace-assignment-periods",
        )->assertForbidden();
    }

    public function test_reading_with_an_unresolved_actual_workplace_is_allowed_on_permission_alone(): void
    {
        $this->principalWithPermissions([HumanResourcesPermissionCatalog::WORKPLACE_ASSIGNMENT_PERIODS_VIEW]);
        $person = $this->createPersonRecord();
        $relationship = $this->createEmploymentRelationship($person);

        $this->getJson(
            "/api/v1/hr/persons/{$person->id}/employment-relationships/{$relationship->id}/workplace-assignment-periods",
        )->assertOk()->assertJsonCount(0);
    }

    // ---------------------------------------------------------------------
    // Audit (spec §S16.15)
    // ---------------------------------------------------------------------

    public function test_starting_and_ending_generate_audit_entries_without_pii(): void
    {
        $this->actingAsHrAdministrator();
        $person = $this->createPersonRecord();
        $relationship = $this->createEmploymentRelationship($person);
        $destination = $this->createUnit();
        $decisionType = $this->assignmentDecisionType();

        $this->postJson(
            "/api/v1/hr/persons/{$person->id}/employment-relationships/{$relationship->id}/workplace-assignment-periods",
            ['organizational_unit_id' => $destination->id, 'effective_from' => '2026-02-01', 'decision_type_id' => $decisionType->id],
        )->assertStatus(201);

        $startEntry = $this->latestAuditEntryFor('hr.workplace_assignment_period.start');
        $this->assertNotNull($startEntry);
        $this->assertStringNotContainsString($person->national_id, json_encode($startEntry->changes));
        $this->assertSame($decisionType->id, $startEntry->changes['decision_type_id']);

        $this->postJson(
            "/api/v1/hr/persons/{$person->id}/employment-relationships/{$relationship->id}/workplace-assignment-periods/end",
            ['effective_to' => '2026-03-01'],
        )->assertOk();

        $endEntry = $this->latestAuditEntryFor('hr.workplace_assignment_period.end');
        $this->assertNotNull($endEntry);
        $this->assertStringNotContainsString($person->national_id, json_encode($endEntry->changes));
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    /** A principal with exactly the given permissions via a fresh role, no scope grant by default. */
    private function principalWithPermissions(array $permissionCodes): Principal
    {
        $principal = $this->createPrincipal();
        $role = $this->createRoleWithPermissions($permissionCodes);
        $this->assignRole($principal, $role);
        $this->actingAs($principal, 'web');

        return $principal;
    }
}
