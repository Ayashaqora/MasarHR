<?php

namespace Tests\Feature\HumanResources;

use App\Modules\HumanResources\Application\Commands\EndEmploymentRelationship;
use App\Modules\HumanResources\Application\Commands\RecordOrganizationalPlacementPeriod;
use App\Modules\HumanResources\Domain\Exceptions\EmploymentRelationshipAlreadyEndedException;
use App\Modules\HumanResources\Domain\Exceptions\InvalidPlacementPeriodDateException;
use App\Modules\HumanResources\Infrastructure\Authorization\HumanResourcesPermissionCatalog;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\OrganizationalPlacementPeriod;
use App\Modules\Organization\Application\Commands\DeactivateOrganizationalUnit;
use App\Modules\Security\Infrastructure\Persistence\Eloquent\Principal;
use Illuminate\Support\Str;

/**
 * S11 Organizational Placement Foundation: transitions, auto-close, temporal integrity, RBAC + S08
 * scope composition, audit, error shape
 * (docs/organizational-placement-foundation-specification.md §6-§10/§18/§22).
 */
class OrganizationalPlacementFoundationTest extends HumanResourcesTestCase
{
    // --- Domain / command ---

    public function test_the_first_placement_period_leaves_the_relationship_open(): void
    {
        $person = $this->createPersonRecord();
        $relationship = $this->createEmploymentRelationship($person);
        $unit = $this->createUnit();

        $period = app(RecordOrganizationalPlacementPeriod::class)->handle($relationship, $unit, '2026-09-27');

        $this->assertSame($relationship->getKey(), $period->employment_relationship_id);
        $this->assertSame($unit->getKey(), $period->organizational_unit_id);
        $this->assertNull($period->effective_to);
        $this->assertSame('NOT_APPLICABLE', $relationship->refresh()->end_knowledge_state);
    }

    public function test_a_second_transition_closes_the_first_period_and_leaves_the_relationship_open(): void
    {
        $person = $this->createPersonRecord();
        $relationship = $this->createEmploymentRelationship($person);
        $unitA = $this->createUnit();
        $unitB = $this->createUnit();
        $command = app(RecordOrganizationalPlacementPeriod::class);

        $first = $command->handle($relationship, $unitA, '2026-09-27');
        $second = $command->handle($relationship, $unitB, '2026-10-01');

        $this->assertSame('2026-10-01', $first->refresh()->effective_to->toDateString());
        $this->assertNull($second->effective_to);
        $this->assertSame('NOT_APPLICABLE', $relationship->refresh()->end_knowledge_state);
    }

    public function test_recording_a_placement_never_ends_the_relationship(): void
    {
        // Spec §13: Placement has no consequence step, unlike S10's status stream.
        $person = $this->createPersonRecord();
        $relationship = $this->createEmploymentRelationship($person);
        $unit = $this->createUnit();

        app(RecordOrganizationalPlacementPeriod::class)->handle($relationship, $unit, '2026-09-27');

        $relationship->refresh();
        $this->assertSame('NOT_APPLICABLE', $relationship->end_knowledge_state);
        $this->assertNull($relationship->effective_to);
        $this->assertFalse($person->refresh()->is_terminal);
    }

    public function test_a_placement_effective_from_not_after_the_relationships_own_effective_from_is_rejected(): void
    {
        $person = $this->createPersonRecord();
        $relationship = $this->createEmploymentRelationship($person, 'permanent', null, '2026-09-27');
        $unit = $this->createUnit();

        $this->expectException(InvalidPlacementPeriodDateException::class);
        app(RecordOrganizationalPlacementPeriod::class)->handle($relationship, $unit, '2026-09-27');
    }

    public function test_a_placement_effective_from_not_after_the_currently_open_periods_effective_from_is_rejected(): void
    {
        $person = $this->createPersonRecord();
        $relationship = $this->createEmploymentRelationship($person);
        $unitA = $this->createUnit();
        $unitB = $this->createUnit();
        $command = app(RecordOrganizationalPlacementPeriod::class);

        $command->handle($relationship, $unitA, '2026-10-01');

        $this->expectException(InvalidPlacementPeriodDateException::class);
        $command->handle($relationship, $unitB, '2026-09-28');
    }

    public function test_recording_a_period_against_an_already_ended_relationship_is_rejected(): void
    {
        $person = $this->createPersonRecord();
        $relationship = $this->createEmploymentRelationship($person);
        $unit = $this->createUnit();

        app(EndEmploymentRelationship::class)
            ->handle($person, $relationship, $relationship->version, '2026-09-27', false);

        $this->expectException(EmploymentRelationshipAlreadyEndedException::class);
        app(RecordOrganizationalPlacementPeriod::class)->handle($relationship, $unit, '2026-10-01');
    }

    // --- API ---

    public function test_recording_a_placement_period_via_the_api_returns_201(): void
    {
        $this->actingAsHrAdministrator();
        $person = $this->createPersonRecord();
        $relationship = $this->createEmploymentRelationship($person);
        $unit = $this->createUnit();

        $this->postJson(
            "/api/v1/hr/persons/{$person->id}/employment-relationships/{$relationship->id}/placement-periods",
            ['organizational_unit_id' => $unit->id, 'effective_from' => '2026-09-27'],
        )->assertStatus(201)->assertJsonPath('organizational_unit_id', $unit->id);
    }

    public function test_listing_placement_periods_via_the_api_orders_most_recent_first(): void
    {
        $this->actingAsHrAdministrator();
        $person = $this->createPersonRecord();
        $relationship = $this->createEmploymentRelationship($person);
        $unitA = $this->createUnit();
        $unitB = $this->createUnit();
        app(RecordOrganizationalPlacementPeriod::class)->handle($relationship, $unitA, '2026-09-27');
        app(RecordOrganizationalPlacementPeriod::class)->handle($relationship, $unitB, '2026-10-01');

        $response = $this->getJson("/api/v1/hr/persons/{$person->id}/employment-relationships/{$relationship->id}/placement-periods");

        // JsonResource::withoutWrapping() is enabled app-wide, so a collection response is a plain
        // top-level array, not {"data": [...]} (S10 precedent).
        $response->assertStatus(200);
        $this->assertSame('2026-10-01', $response->json('0.effective_from'));
        $this->assertSame('2026-09-27', $response->json('1.effective_from'));
    }

    public function test_recording_with_an_unknown_organizational_unit_id_is_a_404(): void
    {
        $this->actingAsHrAdministrator();
        $person = $this->createPersonRecord();
        $relationship = $this->createEmploymentRelationship($person);

        $this->postJson(
            "/api/v1/hr/persons/{$person->id}/employment-relationships/{$relationship->id}/placement-periods",
            ['organizational_unit_id' => (string) Str::uuid7(), 'effective_from' => '2026-09-27'],
        )->assertStatus(404);
    }

    public function test_an_invalid_effective_from_via_the_api_is_a_422(): void
    {
        $this->actingAsHrAdministrator();
        $person = $this->createPersonRecord();
        $relationship = $this->createEmploymentRelationship($person);
        $unit = $this->createUnit();

        $this->postJson(
            "/api/v1/hr/persons/{$person->id}/employment-relationships/{$relationship->id}/placement-periods",
            ['organizational_unit_id' => $unit->id, 'effective_from' => 'not-a-date'],
        )->assertStatus(422)->assertJsonValidationErrors(['effective_from']);
    }

    public function test_a_temporal_ordering_violation_via_the_api_is_a_422_with_field_error(): void
    {
        $this->actingAsHrAdministrator();
        $person = $this->createPersonRecord();
        $relationship = $this->createEmploymentRelationship($person, 'permanent', null, '2026-09-27');
        $unit = $this->createUnit();

        $this->postJson(
            "/api/v1/hr/persons/{$person->id}/employment-relationships/{$relationship->id}/placement-periods",
            ['organizational_unit_id' => $unit->id, 'effective_from' => '2026-09-27'],
        )->assertStatus(422)->assertJsonValidationErrors(['effective_from']);
    }

    public function test_missing_required_fields_via_the_api_is_a_422(): void
    {
        $this->actingAsHrAdministrator();
        $person = $this->createPersonRecord();
        $relationship = $this->createEmploymentRelationship($person);

        $this->postJson(
            "/api/v1/hr/persons/{$person->id}/employment-relationships/{$relationship->id}/placement-periods",
            [],
        )->assertStatus(422)->assertJsonValidationErrors(['organizational_unit_id', 'effective_from']);
    }

    public function test_unauthenticated_requests_are_rejected(): void
    {
        $person = $this->createPersonRecord();
        $relationship = $this->createEmploymentRelationship($person);
        $unit = $this->createUnit();

        $this->getJson("/api/v1/hr/persons/{$person->id}/employment-relationships/{$relationship->id}/placement-periods")
            ->assertStatus(401);
        $this->postJson(
            "/api/v1/hr/persons/{$person->id}/employment-relationships/{$relationship->id}/placement-periods",
            ['organizational_unit_id' => $unit->id, 'effective_from' => '2026-09-27'],
        )->assertStatus(401);
    }

    public function test_a_principal_without_the_permission_is_forbidden_even_with_global_scope(): void
    {
        $principal = $this->createPrincipal();
        $this->grantGlobalScope($principal);
        $this->actingAs($principal, 'web');
        $person = $this->createPersonRecord();
        $relationship = $this->createEmploymentRelationship($person);
        $unit = $this->createUnit();

        $this->postJson(
            "/api/v1/hr/persons/{$person->id}/employment-relationships/{$relationship->id}/placement-periods",
            ['organizational_unit_id' => $unit->id, 'effective_from' => '2026-09-27'],
        )->assertStatus(403);
    }

    public function test_a_relationship_not_belonging_to_the_given_person_is_a_404_idor_protection(): void
    {
        $this->actingAsHrAdministrator();
        $personA = $this->createPersonRecord();
        $personB = $this->createPersonRecord();
        $relationshipForB = $this->createEmploymentRelationship($personB);
        $unit = $this->createUnit();

        $this->postJson(
            "/api/v1/hr/persons/{$personA->id}/employment-relationships/{$relationshipForB->id}/placement-periods",
            ['organizational_unit_id' => $unit->id, 'effective_from' => '2026-09-27'],
        )->assertStatus(404);

        $this->getJson("/api/v1/hr/persons/{$personA->id}/employment-relationships/{$relationshipForB->id}/placement-periods")
            ->assertStatus(404);
    }

    public function test_no_patch_or_delete_route_exists_for_placement_periods(): void
    {
        $person = $this->createPersonRecord();
        $relationship = $this->createEmploymentRelationship($person);
        $path = "/api/v1/hr/persons/{$person->id}/employment-relationships/{$relationship->id}/placement-periods";

        $this->actingAsHrAdministrator();
        $this->patchJson($path, [])->assertStatus(405);
        $this->deleteJson($path)->assertStatus(405);
    }

    // --- S08 organizational scope composition (spec §10/§16) ---

    private function principalWithPermissionOnly(): Principal
    {
        $principal = $this->createPrincipal();
        $role = $this->createRoleWithPermissions([
            HumanResourcesPermissionCatalog::ORGANIZATIONAL_PLACEMENT_PERIODS_VIEW,
            HumanResourcesPermissionCatalog::ORGANIZATIONAL_PLACEMENT_PERIODS_RECORD,
        ]);
        $this->assignRole($principal, $role);
        $this->actingAs($principal, 'web');

        return $principal;
    }

    public function test_a_principal_with_permission_but_no_scope_grant_is_forbidden(): void
    {
        $principal = $this->principalWithPermissionOnly();
        $person = $this->createPersonRecord();
        $relationship = $this->createEmploymentRelationship($person);
        $unit = $this->createUnit();

        $this->postJson(
            "/api/v1/hr/persons/{$person->id}/employment-relationships/{$relationship->id}/placement-periods",
            ['organizational_unit_id' => $unit->id, 'effective_from' => '2026-09-27'],
        )->assertStatus(403);

        $this->assertSame(0, OrganizationalPlacementPeriod::query()->count(), 'nothing was written on a denied request');
    }

    public function test_a_principal_with_permission_and_a_covering_unit_scope_is_allowed(): void
    {
        $principal = $this->principalWithPermissionOnly();
        $unit = $this->createUnit();
        $this->grantUnitScope($principal, $unit);
        $person = $this->createPersonRecord();
        $relationship = $this->createEmploymentRelationship($person);

        $this->postJson(
            "/api/v1/hr/persons/{$person->id}/employment-relationships/{$relationship->id}/placement-periods",
            ['organizational_unit_id' => $unit->id, 'effective_from' => '2026-09-27'],
        )->assertStatus(201);
    }

    public function test_a_principal_with_permission_and_a_covering_unit_scope_over_a_descendant_is_allowed(): void
    {
        // Proves real end-to-end composition with S08's own subtree resolution, not a stub.
        $principal = $this->principalWithPermissionOnly();
        $parent = $this->createUnit();
        $child = $this->createUnit(parentId: $parent->getKey());
        $this->grantUnitScope($principal, $parent);
        $person = $this->createPersonRecord();
        $relationship = $this->createEmploymentRelationship($person);

        $this->postJson(
            "/api/v1/hr/persons/{$person->id}/employment-relationships/{$relationship->id}/placement-periods",
            ['organizational_unit_id' => $child->id, 'effective_from' => '2026-09-27'],
        )->assertStatus(201);
    }

    public function test_a_principal_with_permission_and_a_non_covering_unit_scope_is_forbidden(): void
    {
        $principal = $this->principalWithPermissionOnly();
        $grantedUnit = $this->createUnit();
        $targetUnit = $this->createUnit();
        $this->grantUnitScope($principal, $grantedUnit);
        $person = $this->createPersonRecord();
        $relationship = $this->createEmploymentRelationship($person);

        $this->postJson(
            "/api/v1/hr/persons/{$person->id}/employment-relationships/{$relationship->id}/placement-periods",
            ['organizational_unit_id' => $targetUnit->id, 'effective_from' => '2026-09-27'],
        )->assertStatus(403);
    }

    public function test_a_principal_with_permission_and_global_scope_is_allowed_for_any_unit(): void
    {
        $principal = $this->principalWithPermissionOnly();
        $this->grantGlobalScope($principal);
        $unit = $this->createUnit();
        $person = $this->createPersonRecord();
        $relationship = $this->createEmploymentRelationship($person);

        $this->postJson(
            "/api/v1/hr/persons/{$person->id}/employment-relationships/{$relationship->id}/placement-periods",
            ['organizational_unit_id' => $unit->id, 'effective_from' => '2026-09-27'],
        )->assertStatus(201);
    }

    public function test_recording_into_an_inactive_unit_is_forbidden_even_with_global_scope(): void
    {
        // ScopedAuthorizationChecker denies an inactive target unconditionally (S08 spec §13),
        // reused here unmodified rather than inventing a second, domain-level active check
        // (spec §8 step 2).
        $principal = $this->principalWithPermissionOnly();
        $this->grantGlobalScope($principal);
        $unit = $this->createUnit();
        app(DeactivateOrganizationalUnit::class)->handle($unit, $unit->version);
        $person = $this->createPersonRecord();
        $relationship = $this->createEmploymentRelationship($person);

        $this->postJson(
            "/api/v1/hr/persons/{$person->id}/employment-relationships/{$relationship->id}/placement-periods",
            ['organizational_unit_id' => $unit->id, 'effective_from' => '2026-09-27'],
        )->assertStatus(403);
    }

    public function test_listing_with_no_placement_recorded_yet_is_allowed_on_permission_alone(): void
    {
        // Spec §10: with no current placement, there is no unit to scope-check against — allowed
        // on the route's own permission check alone, with an empty result.
        $this->principalWithPermissionOnly();
        $person = $this->createPersonRecord();
        $relationship = $this->createEmploymentRelationship($person);

        $response = $this->getJson("/api/v1/hr/persons/{$person->id}/employment-relationships/{$relationship->id}/placement-periods");

        $response->assertStatus(200);
        $this->assertSame([], $response->json());
    }

    public function test_listing_is_denied_when_the_current_placements_unit_is_out_of_scope(): void
    {
        $principal = $this->principalWithPermissionOnly();
        $grantedUnit = $this->createUnit();
        $actualUnit = $this->createUnit();
        $this->grantUnitScope($principal, $grantedUnit);
        $person = $this->createPersonRecord();
        $relationship = $this->createEmploymentRelationship($person);
        app(RecordOrganizationalPlacementPeriod::class)->handle($relationship, $actualUnit, '2026-09-27');

        $this->getJson("/api/v1/hr/persons/{$person->id}/employment-relationships/{$relationship->id}/placement-periods")
            ->assertStatus(403);
    }

    // --- Audit ---

    public function test_an_audit_entry_is_recorded_without_national_id(): void
    {
        $this->actingAsHrAdministrator();
        $person = $this->createPersonRecord('9998887771');
        $relationship = $this->createEmploymentRelationship($person);
        $unit = $this->createUnit();

        $this->postJson(
            "/api/v1/hr/persons/{$person->id}/employment-relationships/{$relationship->id}/placement-periods",
            ['organizational_unit_id' => $unit->id, 'effective_from' => '2026-09-27'],
        )->assertStatus(201);

        $entry = $this->latestAuditEntryFor('hr.organizational_placement_period.record');

        $this->assertNotNull($entry);
        $this->assertStringNotContainsString('9998887771', json_encode($entry->changes));
        $this->assertStringNotContainsString('9998887771', json_encode($entry->metadata));
        $this->assertSame($unit->getKey(), $entry->changes['organizational_unit_id']);
    }
}
