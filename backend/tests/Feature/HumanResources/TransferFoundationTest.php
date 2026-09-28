<?php

namespace Tests\Feature\HumanResources;

use App\Modules\HumanResources\Application\Commands\StartFullSecondment;
use App\Modules\HumanResources\Application\Commands\StartWorkplaceAssignment;
use App\Modules\HumanResources\Application\Commands\TransferEmployee;
use App\Modules\HumanResources\Application\Queries\ResolveActualWorkplaceForRelationship;
use App\Modules\HumanResources\Domain\Exceptions\EmploymentRelationshipAlreadyEndedException;
use App\Modules\HumanResources\Domain\Exceptions\InvalidPlacementPeriodDateException;
use App\Modules\HumanResources\Domain\Exceptions\InvalidTransferDecisionTypeException;
use App\Modules\HumanResources\Infrastructure\Authorization\HumanResourcesPermissionCatalog;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\FullSecondmentPeriod;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\OrganizationalPlacementPeriod;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\WorkplaceAssignmentPeriod;
use App\Modules\Organization\Application\Commands\DeactivateOrganizationalUnit;
use App\Modules\Reference\Application\Commands\CreateDecisionType;
use App\Modules\Reference\Application\Commands\DeactivateDecisionType;
use App\Modules\Security\Infrastructure\Persistence\Eloquent\Principal;
use Illuminate\Support\Str;

/**
 * S14 Transfer Foundation (docs/transfer-foundation-specification.md, ADR-S14-001/ADR-S14-002):
 * TransferEmployee's domain behaviour, its atomic S11/S12 consequence wiring, the §12.1 triple-scope
 * authorization rule, the ADR-S14-002 decision-type validation gate, and the API surface. Mirrors
 * FullSecondmentFoundationTest's (S12) structural conventions throughout.
 */
class TransferFoundationTest extends HumanResourcesTestCase
{
    // ---------------------------------------------------------------------
    // Domain / command behaviour
    // ---------------------------------------------------------------------

    public function test_transfer_closes_the_current_placement_and_opens_the_destination(): void
    {
        $person = $this->createPersonRecord();
        $relationship = $this->createEmploymentRelationship($person);
        $origin = $this->createUnit();
        $destination = $this->createUnit();
        $this->recordPlacement($relationship, $origin, '2026-01-15');

        $result = app(TransferEmployee::class)->handle($relationship, $destination, '2026-03-01', $this->transferDecisionType());

        $this->assertSame($destination->id, $result->placement()->organizational_unit_id);
        $this->assertNull($result->placement()->effective_to);
        $this->assertNull($result->closedSecondment());

        $closedOrigin = OrganizationalPlacementPeriod::query()
            ->where('employment_relationship_id', $relationship->id)
            ->where('organizational_unit_id', $origin->id)
            ->firstOrFail();
        $this->assertSame('2026-03-01', $closedOrigin->effective_to->toDateString());
    }

    public function test_transfer_with_no_prior_placement_simply_opens_one(): void
    {
        $person = $this->createPersonRecord();
        $relationship = $this->createEmploymentRelationship($person);
        $destination = $this->createUnit();

        $result = app(TransferEmployee::class)->handle($relationship, $destination, '2026-03-01', $this->transferDecisionType());

        $this->assertSame($destination->id, $result->placement()->organizational_unit_id);
        $this->assertSame(1, OrganizationalPlacementPeriod::query()->where('employment_relationship_id', $relationship->id)->count());
    }

    /** §8/§12 step 4: the consequence — closes an active full secondment at the same effective date. */
    public function test_transfer_closes_an_active_full_secondment_as_a_consequence(): void
    {
        $person = $this->createPersonRecord();
        $relationship = $this->createEmploymentRelationship($person);
        $origin = $this->createUnit();
        $secondmentUnit = $this->createUnit();
        $destination = $this->createUnit();
        $this->recordPlacement($relationship, $origin, '2026-01-15');
        app(StartFullSecondment::class)->handle($relationship, $secondmentUnit, '2026-02-01');

        $result = app(TransferEmployee::class)->handle($relationship, $destination, '2026-03-01', $this->transferDecisionType());

        $this->assertNotNull($result->closedSecondment());
        $this->assertSame('2026-03-01', $result->closedSecondment()->effective_to->toDateString());

        $secondment = FullSecondmentPeriod::query()->where('employment_relationship_id', $relationship->id)->firstOrFail();
        $this->assertSame('2026-03-01', $secondment->effective_to->toDateString());
    }

    public function test_transfer_with_no_active_secondment_leaves_the_secondment_stream_untouched(): void
    {
        $person = $this->createPersonRecord();
        $relationship = $this->createEmploymentRelationship($person);
        $destination = $this->createUnit();

        app(TransferEmployee::class)->handle($relationship, $destination, '2026-03-01', $this->transferDecisionType());

        $this->assertSame(0, FullSecondmentPeriod::query()->where('employment_relationship_id', $relationship->id)->count());
    }

    /**
     * S16 spec §S16.8 movement interaction matrix, pair "Assignment → Transfer": the consequence —
     * closes an active workplace assignment at the same effective date, mirroring
     * test_transfer_closes_an_active_full_secondment_as_a_consequence exactly.
     */
    public function test_transfer_closes_an_active_workplace_assignment_as_a_consequence(): void
    {
        $person = $this->createPersonRecord();
        $relationship = $this->createEmploymentRelationship($person);
        $origin = $this->createUnit();
        $assignmentUnit = $this->createUnit();
        $destination = $this->createUnit();
        $this->recordPlacement($relationship, $origin, '2026-01-15');
        app(StartWorkplaceAssignment::class)->handle($relationship, $assignmentUnit, '2026-02-01', $this->assignmentDecisionType());

        $result = app(TransferEmployee::class)->handle($relationship, $destination, '2026-03-01', $this->transferDecisionType());

        $this->assertNotNull($result->closedAssignment());
        $this->assertSame('2026-03-01', $result->closedAssignment()->effective_to->toDateString());

        $assignment = WorkplaceAssignmentPeriod::query()->where('employment_relationship_id', $relationship->id)->firstOrFail();
        $this->assertSame('2026-03-01', $assignment->effective_to->toDateString());
    }

    public function test_transfer_with_no_active_assignment_leaves_the_assignment_stream_untouched(): void
    {
        $person = $this->createPersonRecord();
        $relationship = $this->createEmploymentRelationship($person);
        $destination = $this->createUnit();

        app(TransferEmployee::class)->handle($relationship, $destination, '2026-03-01', $this->transferDecisionType());

        $this->assertSame(0, WorkplaceAssignmentPeriod::query()->where('employment_relationship_id', $relationship->id)->count());
    }

    public function test_transfer_after_which_actual_workplace_resolves_to_the_new_destination(): void
    {
        $person = $this->createPersonRecord();
        $relationship = $this->createEmploymentRelationship($person);
        $origin = $this->createUnit();
        $secondmentUnit = $this->createUnit();
        $destination = $this->createUnit();
        $this->recordPlacement($relationship, $origin, '2026-01-15');
        app(StartFullSecondment::class)->handle($relationship, $secondmentUnit, '2026-02-01');

        app(TransferEmployee::class)->handle($relationship, $destination, '2026-03-01', $this->transferDecisionType());

        $workplace = app(ResolveActualWorkplaceForRelationship::class)($relationship);
        $this->assertSame($destination->id, $workplace->organizationalUnitId());
        $this->assertSame('placement', $workplace->source());
    }

    public function test_transfer_against_an_already_ended_relationship_is_rejected(): void
    {
        $person = $this->createPersonRecord();
        $relationship = $this->createEmploymentRelationship($person, effectiveFrom: '2026-01-01');
        $relationship->forceFill(['effective_to' => '2026-02-01', 'end_knowledge_state' => 'KNOWN'])->save();

        $this->expectException(EmploymentRelationshipAlreadyEndedException::class);

        app(TransferEmployee::class)->handle($relationship, $this->createUnit(), '2026-03-01', $this->transferDecisionType());
    }

    public function test_transfer_with_a_backdated_effective_from_is_rejected(): void
    {
        $person = $this->createPersonRecord();
        $relationship = $this->createEmploymentRelationship($person, effectiveFrom: '2026-01-01');

        $this->expectException(InvalidPlacementPeriodDateException::class);

        app(TransferEmployee::class)->handle($relationship, $this->createUnit(), '2026-01-01', $this->transferDecisionType());
    }

    // ---------------------------------------------------------------------
    // ADR-S14-002 requirements 6–9: decision-type validation
    // ---------------------------------------------------------------------

    /** ADR-S14-002 requirement 7: rejects a decision type other than TRANSFER. */
    public function test_transfer_rejects_a_decision_type_that_is_not_transfer(): void
    {
        $person = $this->createPersonRecord();
        $relationship = $this->createEmploymentRelationship($person);
        $notTransfer = app(CreateDecisionType::class)->handle('dt_'.Str::lower(Str::random(10)), 'قرار آخر', null, null);

        $this->expectException(InvalidTransferDecisionTypeException::class);

        app(TransferEmployee::class)->handle($relationship, $this->createUnit(), '2026-03-01', $notTransfer);
    }

    /** ADR-S14-002 requirement 8: rejects an inactive TRANSFER. */
    public function test_transfer_rejects_an_inactive_transfer_decision_type(): void
    {
        $person = $this->createPersonRecord();
        $relationship = $this->createEmploymentRelationship($person);
        $transfer = $this->transferDecisionType();
        app(DeactivateDecisionType::class)->handle($transfer, $transfer->version);

        $this->expectException(InvalidTransferDecisionTypeException::class);

        app(TransferEmployee::class)->handle($relationship, $this->createUnit(), '2026-03-01', $transfer->refresh());
    }

    /** ADR-S14-002 requirement 9: valid active TRANSFER succeeds. */
    public function test_transfer_succeeds_with_a_valid_active_transfer_decision_type(): void
    {
        $person = $this->createPersonRecord();
        $relationship = $this->createEmploymentRelationship($person);

        $result = app(TransferEmployee::class)->handle($relationship, $this->createUnit(), '2026-03-01', $this->transferDecisionType());

        $this->assertNotNull($result->placement());
    }

    /**
     * The stable discriminator checked is `code`, never Arabic display text (ADR-S14-002's own
     * explicit instruction) — a decision type whose name_ar happens to also read نقل but whose code
     * is not TRANSFER must still be rejected.
     */
    public function test_transfer_validation_depends_on_code_not_on_arabic_display_text(): void
    {
        $person = $this->createPersonRecord();
        $relationship = $this->createEmploymentRelationship($person);
        $lookAlike = app(CreateDecisionType::class)->handle('not_transfer_'.Str::lower(Str::random(6)), 'نقل', null, null);

        $this->expectException(InvalidTransferDecisionTypeException::class);

        app(TransferEmployee::class)->handle($relationship, $this->createUnit(), '2026-03-01', $lookAlike);
    }

    // ---------------------------------------------------------------------
    // API
    // ---------------------------------------------------------------------

    public function test_store_returns_201_and_the_transfer_result(): void
    {
        $this->actingAsHrAdministrator();
        $person = $this->createPersonRecord();
        $relationship = $this->createEmploymentRelationship($person);
        $origin = $this->createUnit();
        $secondmentUnit = $this->createUnit();
        $destination = $this->createUnit();
        $this->recordPlacement($relationship, $origin, '2026-01-15');
        app(StartFullSecondment::class)->handle($relationship, $secondmentUnit, '2026-02-01');

        $response = $this->postJson(
            "/api/v1/hr/persons/{$person->id}/employment-relationships/{$relationship->id}/transfer",
            [
                'organizational_unit_id' => $destination->id,
                'effective_from' => '2026-03-01',
                'decision_type_id' => $this->transferDecisionType()->id,
            ],
        );

        $response->assertStatus(201)
            ->assertJsonPath('organizational_placement_period.organizational_unit_id', $destination->id)
            ->assertJsonPath('organizational_placement_period.effective_to', null)
            ->assertJsonPath('closed_full_secondment_period.effective_to', '2026-03-01');
    }

    public function test_store_with_no_active_secondment_returns_a_null_closed_secondment(): void
    {
        $this->actingAsHrAdministrator();
        $person = $this->createPersonRecord();
        $relationship = $this->createEmploymentRelationship($person);
        $destination = $this->createUnit();

        $this->postJson(
            "/api/v1/hr/persons/{$person->id}/employment-relationships/{$relationship->id}/transfer",
            [
                'organizational_unit_id' => $destination->id,
                'effective_from' => '2026-03-01',
                'decision_type_id' => $this->transferDecisionType()->id,
            ],
        )->assertStatus(201)->assertJsonPath('closed_full_secondment_period', null)->assertJsonPath('closed_workplace_assignment_period', null);
    }

    /** S16 spec §S16.8/§S16.16: the API surfaces the new consequence exactly like the secondment one. */
    public function test_store_returns_the_closed_workplace_assignment_period(): void
    {
        $this->actingAsHrAdministrator();
        $person = $this->createPersonRecord();
        $relationship = $this->createEmploymentRelationship($person);
        $assignmentUnit = $this->createUnit();
        $destination = $this->createUnit();
        app(StartWorkplaceAssignment::class)->handle($relationship, $assignmentUnit, '2026-02-01', $this->assignmentDecisionType());

        $response = $this->postJson(
            "/api/v1/hr/persons/{$person->id}/employment-relationships/{$relationship->id}/transfer",
            [
                'organizational_unit_id' => $destination->id,
                'effective_from' => '2026-03-01',
                'decision_type_id' => $this->transferDecisionType()->id,
            ],
        );

        $response->assertStatus(201)->assertJsonPath('closed_workplace_assignment_period.effective_to', '2026-03-01');
    }

    public function test_store_with_unknown_organizational_unit_is_404(): void
    {
        $this->actingAsHrAdministrator();
        $person = $this->createPersonRecord();
        $relationship = $this->createEmploymentRelationship($person);

        $this->postJson(
            "/api/v1/hr/persons/{$person->id}/employment-relationships/{$relationship->id}/transfer",
            [
                'organizational_unit_id' => (string) Str::uuid7(),
                'effective_from' => '2026-03-01',
                'decision_type_id' => $this->transferDecisionType()->id,
            ],
        )->assertNotFound();
    }

    public function test_store_with_unknown_decision_type_is_404(): void
    {
        $this->actingAsHrAdministrator();
        $person = $this->createPersonRecord();
        $relationship = $this->createEmploymentRelationship($person);

        $this->postJson(
            "/api/v1/hr/persons/{$person->id}/employment-relationships/{$relationship->id}/transfer",
            [
                'organizational_unit_id' => $this->createUnit()->id,
                'effective_from' => '2026-03-01',
                'decision_type_id' => (string) Str::uuid7(),
            ],
        )->assertNotFound();
    }

    /** ADR-S14-002 requirement 6: rejects missing decision_type_id. */
    public function test_store_missing_decision_type_id_is_422(): void
    {
        $this->actingAsHrAdministrator();
        $person = $this->createPersonRecord();
        $relationship = $this->createEmploymentRelationship($person);

        $this->postJson(
            "/api/v1/hr/persons/{$person->id}/employment-relationships/{$relationship->id}/transfer",
            ['organizational_unit_id' => $this->createUnit()->id, 'effective_from' => '2026-03-01'],
        )->assertStatus(422);
    }

    public function test_store_missing_fields_is_422(): void
    {
        $this->actingAsHrAdministrator();
        $person = $this->createPersonRecord();
        $relationship = $this->createEmploymentRelationship($person);

        $this->postJson(
            "/api/v1/hr/persons/{$person->id}/employment-relationships/{$relationship->id}/transfer",
            [],
        )->assertStatus(422);
    }

    public function test_store_with_a_decision_type_other_than_transfer_is_422(): void
    {
        $this->actingAsHrAdministrator();
        $person = $this->createPersonRecord();
        $relationship = $this->createEmploymentRelationship($person);
        $notTransfer = app(CreateDecisionType::class)->handle('dt_'.Str::lower(Str::random(10)), 'قرار آخر', null, null);

        $this->postJson(
            "/api/v1/hr/persons/{$person->id}/employment-relationships/{$relationship->id}/transfer",
            [
                'organizational_unit_id' => $this->createUnit()->id,
                'effective_from' => '2026-03-01',
                'decision_type_id' => $notTransfer->id,
            ],
        )->assertStatus(422)->assertJsonValidationErrors(['decision_type_id']);
    }

    public function test_unauthenticated_requests_are_401(): void
    {
        $person = $this->createPersonRecord();
        $relationship = $this->createEmploymentRelationship($person);

        $this->postJson("/api/v1/hr/persons/{$person->id}/employment-relationships/{$relationship->id}/transfer", [])
            ->assertUnauthorized();
    }

    public function test_a_relationship_not_belonging_to_the_given_person_is_a_404_idor_protection(): void
    {
        $this->actingAsHrAdministrator();
        $personA = $this->createPersonRecord();
        $personB = $this->createPersonRecord();
        $relationshipOfB = $this->createEmploymentRelationship($personB);

        $this->postJson(
            "/api/v1/hr/persons/{$personA->id}/employment-relationships/{$relationshipOfB->id}/transfer",
            [
                'organizational_unit_id' => $this->createUnit()->id,
                'effective_from' => '2026-03-01',
                'decision_type_id' => $this->transferDecisionType()->id,
            ],
        )->assertNotFound();
    }

    public function test_no_patch_or_delete_route_exists_for_transfer(): void
    {
        $this->actingAsHrAdministrator();
        $person = $this->createPersonRecord();
        $relationship = $this->createEmploymentRelationship($person);

        $this->patchJson("/api/v1/hr/persons/{$person->id}/employment-relationships/{$relationship->id}/transfer")->assertStatus(405);
        $this->deleteJson("/api/v1/hr/persons/{$person->id}/employment-relationships/{$relationship->id}/transfer")->assertStatus(405);
        $this->getJson("/api/v1/hr/persons/{$person->id}/employment-relationships/{$relationship->id}/transfer")->assertStatus(405);
    }

    // ---------------------------------------------------------------------
    // Authorization / triple-scope matrix (spec §13)
    // ---------------------------------------------------------------------

    public function test_transfer_without_any_scope_grant_is_forbidden(): void
    {
        $this->principalWithPermissions([HumanResourcesPermissionCatalog::EMPLOYMENT_RELATIONSHIPS_TRANSFER]);
        $person = $this->createPersonRecord();
        $relationship = $this->createEmploymentRelationship($person);

        $this->postJson(
            "/api/v1/hr/persons/{$person->id}/employment-relationships/{$relationship->id}/transfer",
            [
                'organizational_unit_id' => $this->createUnit()->id,
                'effective_from' => '2026-03-01',
                'decision_type_id' => $this->transferDecisionType()->id,
            ],
        )->assertForbidden();
    }

    public function test_transfer_with_scope_over_destination_only_is_forbidden_when_source_is_out_of_scope(): void
    {
        $principal = $this->principalWithPermissions([HumanResourcesPermissionCatalog::EMPLOYMENT_RELATIONSHIPS_TRANSFER]);
        $person = $this->createPersonRecord();
        $relationship = $this->createEmploymentRelationship($person);
        $source = $this->createUnit();
        $destination = $this->createUnit();
        $this->recordPlacement($relationship, $source, '2026-01-15');
        $this->grantUnitScope($principal, $destination);

        $this->postJson(
            "/api/v1/hr/persons/{$person->id}/employment-relationships/{$relationship->id}/transfer",
            [
                'organizational_unit_id' => $destination->id,
                'effective_from' => '2026-03-01',
                'decision_type_id' => $this->transferDecisionType()->id,
            ],
        )->assertForbidden();

        $this->assertDatabaseCount('hr.organizational_placement_periods', 1);
    }

    public function test_transfer_with_scope_over_source_only_is_forbidden_when_destination_is_out_of_scope(): void
    {
        $principal = $this->principalWithPermissions([HumanResourcesPermissionCatalog::EMPLOYMENT_RELATIONSHIPS_TRANSFER]);
        $person = $this->createPersonRecord();
        $relationship = $this->createEmploymentRelationship($person);
        $source = $this->createUnit();
        $destination = $this->createUnit();
        $this->recordPlacement($relationship, $source, '2026-01-15');
        $this->grantUnitScope($principal, $source);

        $this->postJson(
            "/api/v1/hr/persons/{$person->id}/employment-relationships/{$relationship->id}/transfer",
            [
                'organizational_unit_id' => $destination->id,
                'effective_from' => '2026-03-01',
                'decision_type_id' => $this->transferDecisionType()->id,
            ],
        )->assertForbidden();
    }

    /** The third target: an active secondment's own unit must also be in scope when it will be closed. */
    public function test_transfer_with_scope_over_destination_and_source_only_is_forbidden_when_the_active_secondment_unit_is_out_of_scope(): void
    {
        $principal = $this->principalWithPermissions([HumanResourcesPermissionCatalog::EMPLOYMENT_RELATIONSHIPS_TRANSFER]);
        $person = $this->createPersonRecord();
        $relationship = $this->createEmploymentRelationship($person);
        $source = $this->createUnit();
        $secondmentUnit = $this->createUnit();
        $destination = $this->createUnit();
        $this->recordPlacement($relationship, $source, '2026-01-15');
        app(StartFullSecondment::class)->handle($relationship, $secondmentUnit, '2026-02-01');
        $this->grantUnitScope($principal, $source);
        $this->grantUnitScope($principal, $destination);

        $this->postJson(
            "/api/v1/hr/persons/{$person->id}/employment-relationships/{$relationship->id}/transfer",
            [
                'organizational_unit_id' => $destination->id,
                'effective_from' => '2026-03-01',
                'decision_type_id' => $this->transferDecisionType()->id,
            ],
        )->assertForbidden();

        $this->assertDatabaseCount('hr.full_secondment_periods', 1);
        $this->assertSame(1, FullSecondmentPeriod::query()->whereNull('effective_to')->count(), 'the secondment must remain open — nothing committed on a forbidden request');
    }

    /**
     * S16's own fourth target (spec §S16.14): an active workplace assignment's own unit must also
     * be in scope when it will be closed as this transfer's consequence — mirrors the secondment
     * test immediately above exactly. A relationship can never have both an active secondment and
     * an active assignment (§S16.8 mutual exclusion), so this is exercised with an assignment in
     * place of a secondment, not alongside one.
     */
    public function test_transfer_with_scope_over_destination_and_source_only_is_forbidden_when_the_active_assignment_unit_is_out_of_scope(): void
    {
        $principal = $this->principalWithPermissions([HumanResourcesPermissionCatalog::EMPLOYMENT_RELATIONSHIPS_TRANSFER]);
        $person = $this->createPersonRecord();
        $relationship = $this->createEmploymentRelationship($person);
        $source = $this->createUnit();
        $assignmentUnit = $this->createUnit();
        $destination = $this->createUnit();
        $this->recordPlacement($relationship, $source, '2026-01-15');
        app(StartWorkplaceAssignment::class)->handle($relationship, $assignmentUnit, '2026-02-01', $this->assignmentDecisionType());
        $this->grantUnitScope($principal, $source);
        $this->grantUnitScope($principal, $destination);

        $this->postJson(
            "/api/v1/hr/persons/{$person->id}/employment-relationships/{$relationship->id}/transfer",
            [
                'organizational_unit_id' => $destination->id,
                'effective_from' => '2026-03-01',
                'decision_type_id' => $this->transferDecisionType()->id,
            ],
        )->assertForbidden();

        $this->assertDatabaseCount('hr.workplace_assignment_periods', 1);
        $this->assertSame(1, WorkplaceAssignmentPeriod::query()->whereNull('effective_to')->count(), 'the assignment must remain open — nothing committed on a forbidden request');
    }

    /**
     * Completes the §18 matrix's remaining two-of-three combinations (post-implementation
     * adversarial review, spec §28): destination+secondment granted, source withheld — proves the
     * source check is not silently satisfied by the other two grants.
     */
    public function test_transfer_with_scope_over_destination_and_secondment_only_is_forbidden_when_source_is_out_of_scope(): void
    {
        $principal = $this->principalWithPermissions([HumanResourcesPermissionCatalog::EMPLOYMENT_RELATIONSHIPS_TRANSFER]);
        $person = $this->createPersonRecord();
        $relationship = $this->createEmploymentRelationship($person);
        $source = $this->createUnit();
        $secondmentUnit = $this->createUnit();
        $destination = $this->createUnit();
        $this->recordPlacement($relationship, $source, '2026-01-15');
        app(StartFullSecondment::class)->handle($relationship, $secondmentUnit, '2026-02-01');
        $this->grantUnitScope($principal, $destination);
        $this->grantUnitScope($principal, $secondmentUnit);

        $this->postJson(
            "/api/v1/hr/persons/{$person->id}/employment-relationships/{$relationship->id}/transfer",
            [
                'organizational_unit_id' => $destination->id,
                'effective_from' => '2026-03-01',
                'decision_type_id' => $this->transferDecisionType()->id,
            ],
        )->assertForbidden();

        $this->assertDatabaseCount('hr.organizational_placement_periods', 1);
    }

    /**
     * Source+secondment granted, destination withheld — proves the destination check (always
     * required, §12.1) is not bypassed merely because both other targets are in scope.
     */
    public function test_transfer_with_scope_over_source_and_secondment_only_is_forbidden_when_destination_is_out_of_scope(): void
    {
        $principal = $this->principalWithPermissions([HumanResourcesPermissionCatalog::EMPLOYMENT_RELATIONSHIPS_TRANSFER]);
        $person = $this->createPersonRecord();
        $relationship = $this->createEmploymentRelationship($person);
        $source = $this->createUnit();
        $secondmentUnit = $this->createUnit();
        $destination = $this->createUnit();
        $this->recordPlacement($relationship, $source, '2026-01-15');
        app(StartFullSecondment::class)->handle($relationship, $secondmentUnit, '2026-02-01');
        $this->grantUnitScope($principal, $source);
        $this->grantUnitScope($principal, $secondmentUnit);

        $this->postJson(
            "/api/v1/hr/persons/{$person->id}/employment-relationships/{$relationship->id}/transfer",
            [
                'organizational_unit_id' => $destination->id,
                'effective_from' => '2026-03-01',
                'decision_type_id' => $this->transferDecisionType()->id,
            ],
        )->assertForbidden();

        $this->assertDatabaseCount('hr.organizational_placement_periods', 1);
    }

    /** Only the secondment unit granted — the weakest single-target case, proves scope over one target never substitutes for the other two. */
    public function test_transfer_with_scope_over_secondment_unit_only_is_forbidden(): void
    {
        $principal = $this->principalWithPermissions([HumanResourcesPermissionCatalog::EMPLOYMENT_RELATIONSHIPS_TRANSFER]);
        $person = $this->createPersonRecord();
        $relationship = $this->createEmploymentRelationship($person);
        $source = $this->createUnit();
        $secondmentUnit = $this->createUnit();
        $destination = $this->createUnit();
        $this->recordPlacement($relationship, $source, '2026-01-15');
        app(StartFullSecondment::class)->handle($relationship, $secondmentUnit, '2026-02-01');
        $this->grantUnitScope($principal, $secondmentUnit);

        $this->postJson(
            "/api/v1/hr/persons/{$person->id}/employment-relationships/{$relationship->id}/transfer",
            [
                'organizational_unit_id' => $destination->id,
                'effective_from' => '2026-03-01',
                'decision_type_id' => $this->transferDecisionType()->id,
            ],
        )->assertForbidden();

        $this->assertDatabaseCount('hr.organizational_placement_periods', 1);
        $this->assertSame(1, FullSecondmentPeriod::query()->whereNull('effective_to')->count(), 'the secondment must remain open — nothing committed on a forbidden request');
    }

    public function test_transfer_with_scope_over_all_three_units_is_allowed(): void
    {
        $principal = $this->principalWithPermissions([HumanResourcesPermissionCatalog::EMPLOYMENT_RELATIONSHIPS_TRANSFER]);
        $person = $this->createPersonRecord();
        $relationship = $this->createEmploymentRelationship($person);
        $source = $this->createUnit();
        $secondmentUnit = $this->createUnit();
        $destination = $this->createUnit();
        $this->recordPlacement($relationship, $source, '2026-01-15');
        app(StartFullSecondment::class)->handle($relationship, $secondmentUnit, '2026-02-01');
        $this->grantUnitScope($principal, $source);
        $this->grantUnitScope($principal, $secondmentUnit);
        $this->grantUnitScope($principal, $destination);

        $this->postJson(
            "/api/v1/hr/persons/{$person->id}/employment-relationships/{$relationship->id}/transfer",
            [
                'organizational_unit_id' => $destination->id,
                'effective_from' => '2026-03-01',
                'decision_type_id' => $this->transferDecisionType()->id,
            ],
        )->assertStatus(201);
    }

    public function test_transfer_with_global_scope_is_allowed_for_any_units(): void
    {
        $principal = $this->principalWithPermissions([HumanResourcesPermissionCatalog::EMPLOYMENT_RELATIONSHIPS_TRANSFER]);
        $this->grantGlobalScope($principal);
        $person = $this->createPersonRecord();
        $relationship = $this->createEmploymentRelationship($person);

        $this->postJson(
            "/api/v1/hr/persons/{$person->id}/employment-relationships/{$relationship->id}/transfer",
            [
                'organizational_unit_id' => $this->createUnit()->id,
                'effective_from' => '2026-03-01',
                'decision_type_id' => $this->transferDecisionType()->id,
            ],
        )->assertStatus(201);
    }

    public function test_transfer_into_an_inactive_destination_is_forbidden_even_with_global_scope(): void
    {
        $principal = $this->principalWithPermissions([HumanResourcesPermissionCatalog::EMPLOYMENT_RELATIONSHIPS_TRANSFER]);
        $this->grantGlobalScope($principal);
        $person = $this->createPersonRecord();
        $relationship = $this->createEmploymentRelationship($person);
        $destination = $this->createUnit();
        app(DeactivateOrganizationalUnit::class)->handle($destination, $destination->version);

        $this->postJson(
            "/api/v1/hr/persons/{$person->id}/employment-relationships/{$relationship->id}/transfer",
            [
                'organizational_unit_id' => $destination->id,
                'effective_from' => '2026-03-01',
                'decision_type_id' => $this->transferDecisionType()->id,
            ],
        )->assertForbidden();
    }

    public function test_transfer_with_no_prior_placement_and_no_secondment_only_checks_destination_scope(): void
    {
        $principal = $this->principalWithPermissions([HumanResourcesPermissionCatalog::EMPLOYMENT_RELATIONSHIPS_TRANSFER]);
        $person = $this->createPersonRecord();
        $relationship = $this->createEmploymentRelationship($person);
        $destination = $this->createUnit();
        $this->grantUnitScope($principal, $destination);

        $this->postJson(
            "/api/v1/hr/persons/{$person->id}/employment-relationships/{$relationship->id}/transfer",
            [
                'organizational_unit_id' => $destination->id,
                'effective_from' => '2026-03-01',
                'decision_type_id' => $this->transferDecisionType()->id,
            ],
        )->assertStatus(201);
    }

    // ---------------------------------------------------------------------
    // Audit
    // ---------------------------------------------------------------------

    public function test_transfer_generates_exactly_one_audit_entry_without_pii(): void
    {
        $this->actingAsHrAdministrator();
        $person = $this->createPersonRecord();
        $relationship = $this->createEmploymentRelationship($person);
        $origin = $this->createUnit();
        $secondmentUnit = $this->createUnit();
        $destination = $this->createUnit();
        $this->recordPlacement($relationship, $origin, '2026-01-15');
        app(StartFullSecondment::class)->handle($relationship, $secondmentUnit, '2026-02-01');
        $before = $this->auditEntriesCount();

        $this->postJson(
            "/api/v1/hr/persons/{$person->id}/employment-relationships/{$relationship->id}/transfer",
            [
                'organizational_unit_id' => $destination->id,
                'effective_from' => '2026-03-01',
                'decision_type_id' => $this->transferDecisionType()->id,
            ],
        )->assertStatus(201);

        $entry = $this->latestAuditEntryFor('hr.transfer.execute');
        $this->assertNotNull($entry);
        $this->assertSame($relationship->id, $entry->target_id);
        $this->assertSame($destination->id, $entry->changes['organizational_unit_id']);
        $this->assertSame($this->transferDecisionType()->id, $entry->changes['decision_type_id']);
        $this->assertNotNull($entry->changes['closed_full_secondment_period_id']);
        $this->assertStringNotContainsString($person->national_id, json_encode($entry->changes));

        // TransferEmployee calls RecordOrganizationalPlacementPeriod's and EndFullSecondment's own
        // handle() in-process (never through a second AuditedCommandExecutor::run()) — exactly one
        // audit entry total for this action, never a second, separately-audited placement/secondment
        // entry (spec §16/§19: "never double-audit").
        $this->assertSame($before + 1, $this->auditEntriesCount(), 'exactly one audit entry — no double-audit from the in-process S11/S12 calls');
        $this->assertNull($this->latestAuditEntryFor('hr.organizational_placement_period.record'));
        $this->assertNull($this->latestAuditEntryFor('hr.full_secondment_period.end'));
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
