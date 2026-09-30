<?php

namespace Tests\Feature\HumanResources;

use App\Modules\HumanResources\Application\Commands\EndFullSecondment;
use App\Modules\HumanResources\Application\Commands\StartFullSecondment;
use App\Modules\HumanResources\Application\Queries\ResolveActualWorkplaceForRelationship;
use App\Modules\HumanResources\Domain\Exceptions\ActiveFullSecondmentAlreadyExistsException;
use App\Modules\HumanResources\Domain\Exceptions\EmploymentRelationshipAlreadyEndedException;
use App\Modules\HumanResources\Domain\Exceptions\InvalidFullSecondmentEndDateException;
use App\Modules\HumanResources\Domain\Exceptions\InvalidFullSecondmentStartDateException;
use App\Modules\HumanResources\Domain\Exceptions\NoActiveFullSecondmentException;
use App\Modules\HumanResources\Infrastructure\Authorization\HumanResourcesPermissionCatalog;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\OrganizationalPlacementPeriod;
use App\Modules\Organization\Application\Commands\DeactivateOrganizationalUnit;
use App\Modules\Security\Infrastructure\Persistence\Eloquent\Principal;
use Illuminate\Support\Str;

/**
 * S12 Full Secondment Foundation (docs/full-secondment-foundation-specification.md): domain
 * behaviour, API surface, the §12.1 dual-scope authorization rule, and actual-workplace
 * resolution (§14). Mirrors OrganizationalPlacementFoundationTest's (S11) structural conventions
 * throughout.
 */
class FullSecondmentFoundationTest extends HumanResourcesTestCase
{
    // ---------------------------------------------------------------------
    // Domain / command behaviour
    // ---------------------------------------------------------------------

    public function test_starting_a_full_secondment_records_an_open_period(): void
    {
        $person = $this->createPersonRecord();
        $relationship = $this->createEmploymentRelationship($person);
        $destination = $this->createUnit();

        $period = app(StartFullSecondment::class)->handle($relationship, $destination, '2026-02-01');

        $this->assertSame($relationship->id, $period->employment_relationship_id);
        $this->assertSame($destination->id, $period->organizational_unit_id);
        $this->assertNull($period->effective_to);
        $this->assertTrue($period->isActive());
    }

    public function test_starting_a_second_full_secondment_while_one_is_active_is_rejected(): void
    {
        $person = $this->createPersonRecord();
        $relationship = $this->createEmploymentRelationship($person);
        $destinationA = $this->createUnit();
        $destinationB = $this->createUnit();

        app(StartFullSecondment::class)->handle($relationship, $destinationA, '2026-02-01');

        $this->expectException(ActiveFullSecondmentAlreadyExistsException::class);

        app(StartFullSecondment::class)->handle($relationship, $destinationB, '2026-03-01');
    }

    public function test_ending_a_full_secondment_closes_the_open_period(): void
    {
        $person = $this->createPersonRecord();
        $relationship = $this->createEmploymentRelationship($person);
        $destination = $this->createUnit();

        app(StartFullSecondment::class)->handle($relationship, $destination, '2026-02-01');
        $ended = app(EndFullSecondment::class)->handle($relationship, '2026-03-01');

        $this->assertSame('2026-03-01', $ended->effective_to->toDateString());
        $this->assertFalse($ended->isActive());
    }

    public function test_starting_after_ending_a_previous_secondment_is_allowed(): void
    {
        $person = $this->createPersonRecord();
        $relationship = $this->createEmploymentRelationship($person);
        $destinationA = $this->createUnit();
        $destinationB = $this->createUnit();

        app(StartFullSecondment::class)->handle($relationship, $destinationA, '2026-02-01');
        app(EndFullSecondment::class)->handle($relationship, '2026-03-01');

        $second = app(StartFullSecondment::class)->handle($relationship, $destinationB, '2026-04-01');

        $this->assertTrue($second->isActive());
        $this->assertSame($destinationB->id, $second->organizational_unit_id);
    }

    public function test_starting_against_an_already_ended_relationship_is_rejected(): void
    {
        $person = $this->createPersonRecord();
        $relationship = $this->createEmploymentRelationship($person, effectiveFrom: '2026-01-01');
        $relationship->forceFill(['effective_to' => '2026-02-01', 'end_knowledge_state' => 'KNOWN'])->save();

        $this->expectException(EmploymentRelationshipAlreadyEndedException::class);

        app(StartFullSecondment::class)->handle($relationship, $this->createUnit(), '2026-03-01');
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

        $period = app(StartFullSecondment::class)->handle($relationship, $destination, '2026-02-01');

        $relationship->forceFill(['effective_to' => '2026-03-01', 'end_knowledge_state' => 'KNOWN'])->save();

        try {
            app(EndFullSecondment::class)->handle($relationship, '2026-03-15');
            $this->fail('an ended relationship must reject the end command');
        } catch (EmploymentRelationshipAlreadyEndedException) {
            $this->assertNull($period->refresh()->effective_to);
        }
    }

    public function test_starting_with_a_backdated_effective_from_is_rejected(): void
    {
        $person = $this->createPersonRecord();
        $relationship = $this->createEmploymentRelationship($person, effectiveFrom: '2026-01-01');

        $this->expectException(InvalidFullSecondmentStartDateException::class);

        app(StartFullSecondment::class)->handle($relationship, $this->createUnit(), '2026-01-01');
    }

    public function test_ending_with_a_backdated_effective_to_is_rejected(): void
    {
        $person = $this->createPersonRecord();
        $relationship = $this->createEmploymentRelationship($person);
        $destination = $this->createUnit();

        app(StartFullSecondment::class)->handle($relationship, $destination, '2026-02-01');

        $this->expectException(InvalidFullSecondmentEndDateException::class);

        app(EndFullSecondment::class)->handle($relationship, '2026-02-01');
    }

    public function test_ending_when_none_active_is_rejected(): void
    {
        $person = $this->createPersonRecord();
        $relationship = $this->createEmploymentRelationship($person);

        $this->expectException(NoActiveFullSecondmentException::class);

        app(EndFullSecondment::class)->handle($relationship, '2026-02-01');
    }

    public function test_starting_never_writes_to_the_placement_stream(): void
    {
        $person = $this->createPersonRecord();
        $relationship = $this->createEmploymentRelationship($person);
        $placementUnit = $this->createUnit();
        $this->recordPlacement($relationship, $placementUnit, '2026-01-15');

        app(StartFullSecondment::class)->handle($relationship, $this->createUnit(), '2026-02-01');

        // The S11 placement stream is untouched — still the original unit, no new row inserted.
        $current = OrganizationalPlacementPeriod::query()
            ->where('employment_relationship_id', $relationship->id)
            ->whereNull('effective_to')
            ->first();
        $this->assertSame($placementUnit->id, $current->organizational_unit_id);
    }

    // ---------------------------------------------------------------------
    // Actual workplace resolution (spec §14)
    // ---------------------------------------------------------------------

    public function test_actual_workplace_is_unresolved_with_no_placement_and_no_secondment(): void
    {
        $person = $this->createPersonRecord();
        $relationship = $this->createEmploymentRelationship($person);

        $workplace = app(ResolveActualWorkplaceForRelationship::class)($relationship);

        $this->assertFalse($workplace->isResolved());
        $this->assertNull($workplace->source());
    }

    public function test_actual_workplace_resolves_to_placement_when_no_active_secondment(): void
    {
        $person = $this->createPersonRecord();
        $relationship = $this->createEmploymentRelationship($person);
        $unit = $this->createUnit();
        $this->recordPlacement($relationship, $unit, '2026-01-15');

        $workplace = app(ResolveActualWorkplaceForRelationship::class)($relationship);

        $this->assertTrue($workplace->isResolved());
        $this->assertSame($unit->id, $workplace->organizationalUnitId());
        $this->assertSame('placement', $workplace->source());
    }

    public function test_actual_workplace_resolves_to_secondment_when_active(): void
    {
        $person = $this->createPersonRecord();
        $relationship = $this->createEmploymentRelationship($person);
        $placementUnit = $this->createUnit();
        $secondmentUnit = $this->createUnit();
        $this->recordPlacement($relationship, $placementUnit, '2026-01-15');
        app(StartFullSecondment::class)->handle($relationship, $secondmentUnit, '2026-02-01');

        $workplace = app(ResolveActualWorkplaceForRelationship::class)($relationship);

        $this->assertSame($secondmentUnit->id, $workplace->organizationalUnitId());
        $this->assertSame('secondment', $workplace->source());
    }

    public function test_actual_workplace_reverts_to_placement_after_secondment_ends(): void
    {
        $person = $this->createPersonRecord();
        $relationship = $this->createEmploymentRelationship($person);
        $placementUnit = $this->createUnit();
        $secondmentUnit = $this->createUnit();
        $this->recordPlacement($relationship, $placementUnit, '2026-01-15');
        app(StartFullSecondment::class)->handle($relationship, $secondmentUnit, '2026-02-01');
        app(EndFullSecondment::class)->handle($relationship, '2026-03-01');

        $workplace = app(ResolveActualWorkplaceForRelationship::class)($relationship);

        $this->assertSame($placementUnit->id, $workplace->organizationalUnitId());
        $this->assertSame('placement', $workplace->source());
    }

    public function test_actual_workplace_is_unresolved_once_relationship_has_ended_even_with_an_open_secondment(): void
    {
        $person = $this->createPersonRecord();
        $relationship = $this->createEmploymentRelationship($person);
        $secondmentUnit = $this->createUnit();
        app(StartFullSecondment::class)->handle($relationship, $secondmentUnit, '2026-02-01');

        // The relationship row is put directly into an ended state with the secondment still open
        // (bypassing EndEmploymentRelationship entirely) so this test isolates
        // ResolveActualWorkplaceForRelationship's own read-time neutralisation from however the row
        // came to be ended — including a state EndEmploymentRelationship itself can no longer
        // produce since S15 (docs/employment-status-lifecycle-consequences-specification.md §8.1),
        // but which could still exist from data ended before S15, or by any other means. The
        // read-time guard is the second, independent layer of defence; §8.1 is the first.
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
            "/api/v1/hr/persons/{$person->id}/employment-relationships/{$relationship->id}/full-secondment-periods",
            ['organizational_unit_id' => $destination->id, 'effective_from' => '2026-02-01'],
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

        app(StartFullSecondment::class)->handle($relationship, $this->createUnit(), '2026-02-01');
        app(EndFullSecondment::class)->handle($relationship, '2026-03-01');
        app(StartFullSecondment::class)->handle($relationship, $this->createUnit(), '2026-04-01');

        $response = $this->getJson(
            "/api/v1/hr/persons/{$person->id}/employment-relationships/{$relationship->id}/full-secondment-periods",
        )->assertOk();

        $this->assertSame('2026-04-01', $response->json('0.effective_from'));
        $this->assertSame('2026-02-01', $response->json('1.effective_from'));
    }

    public function test_end_returns_200_and_the_closed_period(): void
    {
        $this->actingAsHrAdministrator();
        $person = $this->createPersonRecord();
        $relationship = $this->createEmploymentRelationship($person);
        app(StartFullSecondment::class)->handle($relationship, $this->createUnit(), '2026-02-01');

        $response = $this->postJson(
            "/api/v1/hr/persons/{$person->id}/employment-relationships/{$relationship->id}/full-secondment-periods/end",
            ['effective_to' => '2026-03-01'],
        );

        $response->assertOk()->assertJsonPath('effective_to', '2026-03-01');
    }

    public function test_actual_workplace_endpoint_returns_the_resolved_unit(): void
    {
        $this->actingAsHrAdministrator();
        $person = $this->createPersonRecord();
        $relationship = $this->createEmploymentRelationship($person);
        $unit = $this->createUnit();
        $this->recordPlacement($relationship, $unit, '2026-01-15');

        $this->getJson(
            "/api/v1/hr/persons/{$person->id}/employment-relationships/{$relationship->id}/actual-workplace",
        )->assertOk()->assertJsonPath('organizational_unit_id', $unit->id)->assertJsonPath('source', 'placement');
    }

    public function test_store_with_unknown_organizational_unit_is_404(): void
    {
        $this->actingAsHrAdministrator();
        $person = $this->createPersonRecord();
        $relationship = $this->createEmploymentRelationship($person);

        $this->postJson(
            "/api/v1/hr/persons/{$person->id}/employment-relationships/{$relationship->id}/full-secondment-periods",
            ['organizational_unit_id' => (string) Str::uuid7(), 'effective_from' => '2026-02-01'],
        )->assertNotFound();
    }

    public function test_store_missing_fields_is_422(): void
    {
        $this->actingAsHrAdministrator();
        $person = $this->createPersonRecord();
        $relationship = $this->createEmploymentRelationship($person);

        $this->postJson(
            "/api/v1/hr/persons/{$person->id}/employment-relationships/{$relationship->id}/full-secondment-periods",
            [],
        )->assertStatus(422);
    }

    public function test_end_missing_fields_is_422(): void
    {
        $this->actingAsHrAdministrator();
        $person = $this->createPersonRecord();
        $relationship = $this->createEmploymentRelationship($person);
        app(StartFullSecondment::class)->handle($relationship, $this->createUnit(), '2026-02-01');

        $this->postJson(
            "/api/v1/hr/persons/{$person->id}/employment-relationships/{$relationship->id}/full-secondment-periods/end",
            [],
        )->assertStatus(422);
    }

    public function test_unauthenticated_requests_are_401(): void
    {
        $person = $this->createPersonRecord();
        $relationship = $this->createEmploymentRelationship($person);

        $this->getJson("/api/v1/hr/persons/{$person->id}/employment-relationships/{$relationship->id}/full-secondment-periods")->assertUnauthorized();
        $this->postJson("/api/v1/hr/persons/{$person->id}/employment-relationships/{$relationship->id}/full-secondment-periods", [])->assertUnauthorized();
        $this->postJson("/api/v1/hr/persons/{$person->id}/employment-relationships/{$relationship->id}/full-secondment-periods/end", [])->assertUnauthorized();
        $this->getJson("/api/v1/hr/persons/{$person->id}/employment-relationships/{$relationship->id}/actual-workplace")->assertUnauthorized();
    }

    public function test_a_relationship_not_belonging_to_the_given_person_is_a_404_idor_protection(): void
    {
        $this->actingAsHrAdministrator();
        $personA = $this->createPersonRecord();
        $personB = $this->createPersonRecord();
        $relationshipOfB = $this->createEmploymentRelationship($personB);

        $this->getJson(
            "/api/v1/hr/persons/{$personA->id}/employment-relationships/{$relationshipOfB->id}/full-secondment-periods",
        )->assertNotFound();
    }

    public function test_no_patch_or_delete_route_exists_for_full_secondment_periods(): void
    {
        $this->actingAsHrAdministrator();
        $person = $this->createPersonRecord();
        $relationship = $this->createEmploymentRelationship($person);

        $this->patchJson("/api/v1/hr/persons/{$person->id}/employment-relationships/{$relationship->id}/full-secondment-periods")->assertStatus(405);
        $this->deleteJson("/api/v1/hr/persons/{$person->id}/employment-relationships/{$relationship->id}/full-secondment-periods")->assertStatus(405);
    }

    // ---------------------------------------------------------------------
    // Authorization / dual-scope matrix (spec §12.1)
    // ---------------------------------------------------------------------

    public function test_starting_without_any_scope_grant_is_forbidden(): void
    {
        $this->principalWithPermissions([HumanResourcesPermissionCatalog::FULL_SECONDMENT_PERIODS_START]);
        $person = $this->createPersonRecord();
        $relationship = $this->createEmploymentRelationship($person);
        $destination = $this->createUnit();

        $this->postJson(
            "/api/v1/hr/persons/{$person->id}/employment-relationships/{$relationship->id}/full-secondment-periods",
            ['organizational_unit_id' => $destination->id, 'effective_from' => '2026-02-01'],
        )->assertForbidden();
    }

    public function test_starting_with_scope_over_destination_only_is_forbidden_when_source_is_out_of_scope(): void
    {
        $principal = $this->principalWithPermissions([HumanResourcesPermissionCatalog::FULL_SECONDMENT_PERIODS_START]);
        $person = $this->createPersonRecord();
        $relationship = $this->createEmploymentRelationship($person);
        $sourceUnit = $this->createUnit();
        $destinationUnit = $this->createUnit();
        $this->recordPlacement($relationship, $sourceUnit, '2026-01-15');

        $this->grantUnitScope($principal, $destinationUnit);

        $this->postJson(
            "/api/v1/hr/persons/{$person->id}/employment-relationships/{$relationship->id}/full-secondment-periods",
            ['organizational_unit_id' => $destinationUnit->id, 'effective_from' => '2026-02-01'],
        )->assertForbidden();

        $this->assertDatabaseCount('hr.full_secondment_periods', 0);
    }

    public function test_starting_with_scope_over_source_only_is_forbidden_when_destination_is_out_of_scope(): void
    {
        $principal = $this->principalWithPermissions([HumanResourcesPermissionCatalog::FULL_SECONDMENT_PERIODS_START]);
        $person = $this->createPersonRecord();
        $relationship = $this->createEmploymentRelationship($person);
        $sourceUnit = $this->createUnit();
        $destinationUnit = $this->createUnit();
        $this->recordPlacement($relationship, $sourceUnit, '2026-01-15');

        $this->grantUnitScope($principal, $sourceUnit);

        $this->postJson(
            "/api/v1/hr/persons/{$person->id}/employment-relationships/{$relationship->id}/full-secondment-periods",
            ['organizational_unit_id' => $destinationUnit->id, 'effective_from' => '2026-02-01'],
        )->assertForbidden();
    }

    public function test_starting_with_scope_over_both_source_and_destination_is_allowed(): void
    {
        $principal = $this->principalWithPermissions([HumanResourcesPermissionCatalog::FULL_SECONDMENT_PERIODS_START]);
        $person = $this->createPersonRecord();
        $relationship = $this->createEmploymentRelationship($person);
        $sourceUnit = $this->createUnit();
        $destinationUnit = $this->createUnit();
        $this->recordPlacement($relationship, $sourceUnit, '2026-01-15');

        $this->grantUnitScope($principal, $sourceUnit);
        $this->grantUnitScope($principal, $destinationUnit);

        $this->postJson(
            "/api/v1/hr/persons/{$person->id}/employment-relationships/{$relationship->id}/full-secondment-periods",
            ['organizational_unit_id' => $destinationUnit->id, 'effective_from' => '2026-02-01'],
        )->assertStatus(201);
    }

    public function test_starting_with_global_scope_is_allowed_for_any_unit(): void
    {
        $principal = $this->principalWithPermissions([HumanResourcesPermissionCatalog::FULL_SECONDMENT_PERIODS_START]);
        $person = $this->createPersonRecord();
        $relationship = $this->createEmploymentRelationship($person);
        $sourceUnit = $this->createUnit();
        $destinationUnit = $this->createUnit();
        $this->recordPlacement($relationship, $sourceUnit, '2026-01-15');

        $this->grantGlobalScope($principal);

        $this->postJson(
            "/api/v1/hr/persons/{$person->id}/employment-relationships/{$relationship->id}/full-secondment-periods",
            ['organizational_unit_id' => $destinationUnit->id, 'effective_from' => '2026-02-01'],
        )->assertStatus(201);
    }

    public function test_starting_into_an_inactive_destination_is_forbidden_even_with_global_scope(): void
    {
        $principal = $this->principalWithPermissions([HumanResourcesPermissionCatalog::FULL_SECONDMENT_PERIODS_START]);
        $this->grantGlobalScope($principal);
        $person = $this->createPersonRecord();
        $relationship = $this->createEmploymentRelationship($person);
        $destination = $this->createUnit();
        app(DeactivateOrganizationalUnit::class)->handle($destination, $destination->version);

        $this->postJson(
            "/api/v1/hr/persons/{$person->id}/employment-relationships/{$relationship->id}/full-secondment-periods",
            ['organizational_unit_id' => $destination->id, 'effective_from' => '2026-02-01'],
        )->assertForbidden();
    }

    public function test_starting_with_no_placement_yet_only_checks_destination_scope(): void
    {
        $principal = $this->principalWithPermissions([HumanResourcesPermissionCatalog::FULL_SECONDMENT_PERIODS_START]);
        $person = $this->createPersonRecord();
        $relationship = $this->createEmploymentRelationship($person);
        $destinationUnit = $this->createUnit();

        // No placement recorded at all — nothing to check scope against on the source side.
        $this->grantUnitScope($principal, $destinationUnit);

        $this->postJson(
            "/api/v1/hr/persons/{$person->id}/employment-relationships/{$relationship->id}/full-secondment-periods",
            ['organizational_unit_id' => $destinationUnit->id, 'effective_from' => '2026-02-01'],
        )->assertStatus(201);
    }

    public function test_ending_without_scope_over_the_secondment_unit_is_forbidden(): void
    {
        $principal = $this->principalWithPermissions([
            HumanResourcesPermissionCatalog::FULL_SECONDMENT_PERIODS_START,
            HumanResourcesPermissionCatalog::FULL_SECONDMENT_PERIODS_END,
        ]);
        $this->grantGlobalScope($principal);
        $person = $this->createPersonRecord();
        $relationship = $this->createEmploymentRelationship($person);
        $secondmentUnit = $this->createUnit();

        $this->postJson(
            "/api/v1/hr/persons/{$person->id}/employment-relationships/{$relationship->id}/full-secondment-periods",
            ['organizational_unit_id' => $secondmentUnit->id, 'effective_from' => '2026-02-01'],
        )->assertStatus(201);

        // Revoke by re-granting a narrower principal instead: build a fresh principal that only
        // ever had START (with GLOBAL) — now test END without any grant for it.
        $endPrincipal = $this->principalWithPermissions([HumanResourcesPermissionCatalog::FULL_SECONDMENT_PERIODS_END]);

        $this->postJson(
            "/api/v1/hr/persons/{$person->id}/employment-relationships/{$relationship->id}/full-secondment-periods/end",
            ['effective_to' => '2026-03-01'],
        )->assertForbidden();
    }

    public function test_ending_with_scope_over_both_units_is_allowed(): void
    {
        $principal = $this->principalWithPermissions([
            HumanResourcesPermissionCatalog::FULL_SECONDMENT_PERIODS_START,
            HumanResourcesPermissionCatalog::FULL_SECONDMENT_PERIODS_END,
        ]);
        $person = $this->createPersonRecord();
        $relationship = $this->createEmploymentRelationship($person);
        $sourceUnit = $this->createUnit();
        $secondmentUnit = $this->createUnit();
        $this->recordPlacement($relationship, $sourceUnit, '2026-01-15');
        $this->grantUnitScope($principal, $sourceUnit);
        $this->grantUnitScope($principal, $secondmentUnit);

        $this->postJson(
            "/api/v1/hr/persons/{$person->id}/employment-relationships/{$relationship->id}/full-secondment-periods",
            ['organizational_unit_id' => $secondmentUnit->id, 'effective_from' => '2026-02-01'],
        )->assertStatus(201);

        $this->postJson(
            "/api/v1/hr/persons/{$person->id}/employment-relationships/{$relationship->id}/full-secondment-periods/end",
            ['effective_to' => '2026-03-01'],
        )->assertOk();
    }

    public function test_ending_with_scope_over_the_secondment_unit_only_is_forbidden_when_source_is_out_of_scope(): void
    {
        $principal = $this->principalWithPermissions([
            HumanResourcesPermissionCatalog::FULL_SECONDMENT_PERIODS_START,
            HumanResourcesPermissionCatalog::FULL_SECONDMENT_PERIODS_END,
        ]);
        $this->grantGlobalScope($principal);
        $person = $this->createPersonRecord();
        $relationship = $this->createEmploymentRelationship($person);
        $sourceUnit = $this->createUnit();
        $secondmentUnit = $this->createUnit();
        $this->recordPlacement($relationship, $sourceUnit, '2026-01-15');

        $this->postJson(
            "/api/v1/hr/persons/{$person->id}/employment-relationships/{$relationship->id}/full-secondment-periods",
            ['organizational_unit_id' => $secondmentUnit->id, 'effective_from' => '2026-02-01'],
        )->assertStatus(201);

        // A fresh END-only principal scoped over the secondment's own unit, but NOT over the
        // underlying source placement unit it would revert to — spec §12.1's "return" direction
        // of the dual-scope rule.
        $endPrincipal = $this->principalWithPermissions([HumanResourcesPermissionCatalog::FULL_SECONDMENT_PERIODS_END]);
        $this->grantUnitScope($endPrincipal, $secondmentUnit);

        $this->postJson(
            "/api/v1/hr/persons/{$person->id}/employment-relationships/{$relationship->id}/full-secondment-periods/end",
            ['effective_to' => '2026-03-01'],
        )->assertForbidden();
    }

    public function test_ending_with_scope_over_the_source_unit_only_is_forbidden_when_the_secondment_unit_is_out_of_scope(): void
    {
        $principal = $this->principalWithPermissions([
            HumanResourcesPermissionCatalog::FULL_SECONDMENT_PERIODS_START,
            HumanResourcesPermissionCatalog::FULL_SECONDMENT_PERIODS_END,
        ]);
        $this->grantGlobalScope($principal);
        $person = $this->createPersonRecord();
        $relationship = $this->createEmploymentRelationship($person);
        $sourceUnit = $this->createUnit();
        $secondmentUnit = $this->createUnit();
        $this->recordPlacement($relationship, $sourceUnit, '2026-01-15');

        $this->postJson(
            "/api/v1/hr/persons/{$person->id}/employment-relationships/{$relationship->id}/full-secondment-periods",
            ['organizational_unit_id' => $secondmentUnit->id, 'effective_from' => '2026-02-01'],
        )->assertStatus(201);

        // A fresh END-only principal scoped over the underlying source unit, but NOT over the
        // secondment's own unit it is ending FROM.
        $endPrincipal = $this->principalWithPermissions([HumanResourcesPermissionCatalog::FULL_SECONDMENT_PERIODS_END]);
        $this->grantUnitScope($endPrincipal, $sourceUnit);

        $this->postJson(
            "/api/v1/hr/persons/{$person->id}/employment-relationships/{$relationship->id}/full-secondment-periods/end",
            ['effective_to' => '2026-03-01'],
        )->assertForbidden();
    }

    public function test_ending_with_none_active_is_allowed_on_permission_alone_and_then_conflicts(): void
    {
        $this->principalWithPermissions([HumanResourcesPermissionCatalog::FULL_SECONDMENT_PERIODS_END]);
        $person = $this->createPersonRecord();
        $relationship = $this->createEmploymentRelationship($person);

        // No scope grant at all, and nothing active to end — the scope check has no unit to
        // check, so the request reaches the command, which supplies the substantive 409.
        $this->postJson(
            "/api/v1/hr/persons/{$person->id}/employment-relationships/{$relationship->id}/full-secondment-periods/end",
            ['effective_to' => '2026-03-01'],
        )->assertStatus(409);
    }

    public function test_reading_index_is_denied_when_the_resolved_actual_workplace_unit_is_out_of_scope(): void
    {
        $principal = $this->principalWithPermissions([HumanResourcesPermissionCatalog::FULL_SECONDMENT_PERIODS_VIEW]);
        $person = $this->createPersonRecord();
        $relationship = $this->createEmploymentRelationship($person);
        $unit = $this->createUnit();
        $this->recordPlacement($relationship, $unit, '2026-01-15');

        $otherUnit = $this->createUnit();
        $this->grantUnitScope($principal, $otherUnit);

        $this->getJson(
            "/api/v1/hr/persons/{$person->id}/employment-relationships/{$relationship->id}/full-secondment-periods",
        )->assertForbidden();
    }

    public function test_reading_with_an_unresolved_actual_workplace_is_allowed_on_permission_alone(): void
    {
        $this->principalWithPermissions([HumanResourcesPermissionCatalog::FULL_SECONDMENT_PERIODS_VIEW]);
        $person = $this->createPersonRecord();
        $relationship = $this->createEmploymentRelationship($person);

        $this->getJson(
            "/api/v1/hr/persons/{$person->id}/employment-relationships/{$relationship->id}/full-secondment-periods",
        )->assertOk()->assertJsonCount(0);

        $this->getJson(
            "/api/v1/hr/persons/{$person->id}/employment-relationships/{$relationship->id}/actual-workplace",
        )->assertOk()->assertJsonPath('organizational_unit_id', null);
    }

    // ---------------------------------------------------------------------
    // Audit
    // ---------------------------------------------------------------------

    public function test_starting_and_ending_generate_audit_entries_without_pii(): void
    {
        $this->actingAsHrAdministrator();
        $person = $this->createPersonRecord();
        $relationship = $this->createEmploymentRelationship($person);
        $destination = $this->createUnit();

        $this->postJson(
            "/api/v1/hr/persons/{$person->id}/employment-relationships/{$relationship->id}/full-secondment-periods",
            ['organizational_unit_id' => $destination->id, 'effective_from' => '2026-02-01'],
        )->assertStatus(201);

        $startEntry = $this->latestAuditEntryFor('hr.full_secondment_period.start');
        $this->assertNotNull($startEntry);
        $this->assertStringNotContainsString($person->national_id, json_encode($startEntry->changes));

        $this->postJson(
            "/api/v1/hr/persons/{$person->id}/employment-relationships/{$relationship->id}/full-secondment-periods/end",
            ['effective_to' => '2026-03-01'],
        )->assertOk();

        $endEntry = $this->latestAuditEntryFor('hr.full_secondment_period.end');
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
