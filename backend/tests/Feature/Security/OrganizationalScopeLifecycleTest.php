<?php

namespace Tests\Feature\Security;

/** S08 grant/revoke lifecycle + audit + error-shape coverage (spec §21). */
class OrganizationalScopeLifecycleTest extends OrganizationalScopeTestCase
{
    public function test_granting_global_scope_succeeds_and_is_audited(): void
    {
        $this->actingAsScopeAdministrator();
        $target = $this->createPrincipal();

        $response = $this->postJson("/api/v1/security/principals/{$target->id}/organizational-scopes", [
            'scope_kind' => 'GLOBAL',
        ])->assertCreated();

        $response->assertJsonPath('scope_kind', 'GLOBAL')->assertJsonPath('organizational_unit_id', null);

        $entry = $this->latestAuditEntryFor('security.organizational_scope.grant');
        $this->assertSame($response->json('id'), $entry->target_id);
    }

    public function test_granting_unit_scope_succeeds_and_is_audited(): void
    {
        $this->actingAsScopeAdministrator();
        $target = $this->createPrincipal();
        $unit = $this->createUnit();

        $response = $this->postJson("/api/v1/security/principals/{$target->id}/organizational-scopes", [
            'scope_kind' => 'UNIT',
            'organizational_unit_id' => $unit->id,
        ])->assertCreated();

        $response->assertJsonPath('scope_kind', 'UNIT')->assertJsonPath('organizational_unit_id', $unit->id);
        $this->assertNotNull($this->latestAuditEntryFor('security.organizational_scope.grant'));
    }

    public function test_unit_scope_without_an_organizational_unit_id_is_a_standardized_validation_error(): void
    {
        $this->actingAsScopeAdministrator();
        $target = $this->createPrincipal();

        $this->postJson("/api/v1/security/principals/{$target->id}/organizational-scopes", [
            'scope_kind' => 'UNIT',
        ])->assertStatus(422)->assertJsonValidationErrors(['organizational_unit_id']);
    }

    public function test_global_scope_with_an_organizational_unit_id_is_a_standardized_validation_error(): void
    {
        $this->actingAsScopeAdministrator();
        $target = $this->createPrincipal();
        $unit = $this->createUnit();

        $this->postJson("/api/v1/security/principals/{$target->id}/organizational-scopes", [
            'scope_kind' => 'GLOBAL',
            'organizational_unit_id' => $unit->id,
        ])->assertStatus(422)->assertJsonValidationErrors(['organizational_unit_id']);
    }

    public function test_an_invalid_scope_kind_is_a_standardized_validation_error(): void
    {
        $this->actingAsScopeAdministrator();
        $target = $this->createPrincipal();

        $this->postJson("/api/v1/security/principals/{$target->id}/organizational-scopes", [
            'scope_kind' => 'BRANCH',
        ])->assertStatus(422)->assertJsonValidationErrors(['scope_kind']);
    }

    public function test_unit_scope_referencing_a_nonexistent_unit_is_rejected_with_404(): void
    {
        $this->actingAsScopeAdministrator();
        $target = $this->createPrincipal();

        $this->postJson("/api/v1/security/principals/{$target->id}/organizational-scopes", [
            'scope_kind' => 'UNIT',
            'organizational_unit_id' => '00000000-0000-0000-0000-000000000000',
        ])->assertStatus(404);
    }

    public function test_granting_the_same_global_scope_twice_is_rejected_with_409(): void
    {
        $this->actingAsScopeAdministrator();
        $target = $this->createPrincipal();
        $this->grantGlobalScope($target);

        $this->postJson("/api/v1/security/principals/{$target->id}/organizational-scopes", [
            'scope_kind' => 'GLOBAL',
        ])->assertStatus(409);
    }

    public function test_granting_the_same_unit_scope_twice_is_rejected_with_409(): void
    {
        $this->actingAsScopeAdministrator();
        $target = $this->createPrincipal();
        $unit = $this->createUnit();
        $this->grantUnitScope($target, $unit);

        $this->postJson("/api/v1/security/principals/{$target->id}/organizational-scopes", [
            'scope_kind' => 'UNIT',
            'organizational_unit_id' => $unit->id,
        ])->assertStatus(409);
    }

    public function test_index_lists_every_grant_for_the_principal(): void
    {
        $this->actingAsScopeAdministrator();
        $target = $this->createPrincipal();
        $unitA = $this->createUnit('A');
        $unitB = $this->createUnit('B');
        $this->grantUnitScope($target, $unitA);
        $this->grantUnitScope($target, $unitB);

        $response = $this->getJson("/api/v1/security/principals/{$target->id}/organizational-scopes")->assertOk();

        $this->assertCount(2, $response->json());
    }

    public function test_revoking_a_grant_succeeds_and_is_audited(): void
    {
        $this->actingAsScopeAdministrator();
        $target = $this->createPrincipal();
        $grant = $this->grantGlobalScope($target);

        $this->deleteJson("/api/v1/security/principals/{$target->id}/organizational-scopes/{$grant->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('security.organizational_scope_grants', ['id' => $grant->id]);
        $this->assertNotNull($this->latestAuditEntryFor('security.organizational_scope.revoke'));
    }

    public function test_revoking_a_grant_that_belongs_to_a_different_principal_is_a_404_not_a_cross_principal_delete(): void
    {
        $this->actingAsScopeAdministrator();
        $target = $this->createPrincipal();
        $someoneElse = $this->createPrincipal();
        $grant = $this->grantGlobalScope($someoneElse);

        $this->deleteJson("/api/v1/security/principals/{$target->id}/organizational-scopes/{$grant->id}")
            ->assertStatus(404);

        $this->assertDatabaseHas('security.organizational_scope_grants', ['id' => $grant->id]);
    }

    public function test_reads_are_rejected_without_authentication(): void
    {
        $target = $this->createPrincipal();

        $this->getJson("/api/v1/security/principals/{$target->id}/organizational-scopes")->assertStatus(401);
    }

    public function test_reads_are_rejected_without_the_scope_administration_permission(): void
    {
        $principal = $this->createPrincipal();
        $this->actingAs($principal, 'web');
        $target = $this->createPrincipal();

        $this->getJson("/api/v1/security/principals/{$target->id}/organizational-scopes")->assertStatus(403);
    }

    public function test_writes_are_rejected_without_the_scope_administration_permission(): void
    {
        $principal = $this->createPrincipal();
        $this->actingAs($principal, 'web');
        $target = $this->createPrincipal();

        $this->postJson("/api/v1/security/principals/{$target->id}/organizational-scopes", ['scope_kind' => 'GLOBAL'])
            ->assertStatus(403);
    }

    public function test_effective_scope_endpoint_is_reachable_and_reflects_grants(): void
    {
        // Proves the route-ordering discipline (spec §18: 'effective' registered before the
        // {organizationalScopeGrant} wildcard) actually works end to end, not only that the two
        // route definitions exist.
        $this->actingAsScopeAdministrator();
        $target = $this->createPrincipal();
        $unit = $this->createUnit();

        $this->getJson("/api/v1/security/principals/{$target->id}/organizational-scopes/effective")
            ->assertOk()
            ->assertJson(['is_global' => false, 'organizational_unit_ids' => []]);

        $this->grantUnitScope($target, $unit);

        $this->getJson("/api/v1/security/principals/{$target->id}/organizational-scopes/effective")
            ->assertOk()
            ->assertJsonPath('is_global', false)
            ->assertJsonPath('organizational_unit_ids', [$unit->id]);

        $this->grantGlobalScope($target);

        $this->getJson("/api/v1/security/principals/{$target->id}/organizational-scopes/effective")
            ->assertOk()
            ->assertJson(['is_global' => true, 'organizational_unit_ids' => []]);
    }

    public function test_error_responses_never_leak_sql_or_stack_traces(): void
    {
        $this->actingAsScopeAdministrator();
        $target = $this->createPrincipal();

        $body = $this->postJson("/api/v1/security/principals/{$target->id}/organizational-scopes", [
            'scope_kind' => 'UNIT',
        ])->getContent();

        foreach (['SQLSTATE', '.php:', 'Stack trace', 'PDOException', 'select *'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $body);
        }
    }
}
