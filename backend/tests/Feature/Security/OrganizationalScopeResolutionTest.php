<?php

namespace Tests\Feature\Security;

use App\Modules\Organization\Application\Commands\MoveOrganizationalUnit;
use App\Modules\Security\Application\Queries\ResolveEffectiveOrganizationalScope;
use App\Modules\Security\Domain\EffectiveOrganizationalScope;
use App\Modules\Security\Infrastructure\Authorization\PermissionCatalog;
use App\Modules\Security\Infrastructure\Authorization\ScopedAuthorizationChecker;
use App\Modules\Security\Infrastructure\Persistence\Eloquent\Principal;

/**
 * S08 effective-scope resolution and ScopedAuthorizationChecker coverage (spec §6/§11/§13/§21):
 * subtree-inclusion semantics (ADR-S08-002), multi-grant union, GLOBAL absorption, RBAC-absent-deny
 * / scope-absent-deny, inactive-target denial, ancestor-inactive-does-not-block, and live
 * re-resolution after a unit move.
 */
class OrganizationalScopeResolutionTest extends OrganizationalScopeTestCase
{
    private function resolve(Principal $principal): EffectiveOrganizationalScope
    {
        return app(ResolveEffectiveOrganizationalScope::class)->__invoke($principal);
    }

    public function test_a_unit_grant_covers_the_unit_itself_and_every_descendant(): void
    {
        $principal = $this->createPrincipal();
        $root = $this->createUnit('Root');
        $middle = $this->createUnit('Middle', $root->id);
        $leaf = $this->createUnit('Leaf', $middle->id);
        $unrelated = $this->createUnit('Unrelated');

        $this->grantUnitScope($principal, $root);
        $scope = $this->resolve($principal);

        $this->assertFalse($scope->isGlobal());
        $this->assertTrue($scope->covers($root->id));
        $this->assertTrue($scope->covers($middle->id));
        $this->assertTrue($scope->covers($leaf->id));
        $this->assertFalse($scope->covers($unrelated->id));
    }

    public function test_a_unit_grant_does_not_cover_its_own_ancestors(): void
    {
        $principal = $this->createPrincipal();
        $root = $this->createUnit('Root');
        $child = $this->createUnit('Child', $root->id);

        $this->grantUnitScope($principal, $child);
        $scope = $this->resolve($principal);

        $this->assertFalse($scope->covers($root->id));
        $this->assertTrue($scope->covers($child->id));
    }

    public function test_multiple_unit_grants_union_their_subtrees(): void
    {
        $principal = $this->createPrincipal();
        $unitA = $this->createUnit('A');
        $childOfA = $this->createUnit('Child of A', $unitA->id);
        $unitB = $this->createUnit('B');
        $unrelated = $this->createUnit('Unrelated');

        $this->grantUnitScope($principal, $unitA);
        $this->grantUnitScope($principal, $unitB);
        $scope = $this->resolve($principal);

        $this->assertTrue($scope->covers($unitA->id));
        $this->assertTrue($scope->covers($childOfA->id));
        $this->assertTrue($scope->covers($unitB->id));
        $this->assertFalse($scope->covers($unrelated->id));
    }

    public function test_a_global_grant_covers_an_arbitrary_unit_with_no_other_grants(): void
    {
        $principal = $this->createPrincipal();
        $unit = $this->createUnit();

        $this->grantGlobalScope($principal);
        $scope = $this->resolve($principal);

        $this->assertTrue($scope->isGlobal());
        $this->assertTrue($scope->covers($unit->id));
    }

    public function test_global_absorbs_any_coexisting_unit_grants(): void
    {
        $principal = $this->createPrincipal();
        $unit = $this->createUnit();

        $this->grantUnitScope($principal, $unit);
        $this->grantGlobalScope($principal);
        $scope = $this->resolve($principal);

        $this->assertTrue($scope->isGlobal());
    }

    public function test_no_grants_at_all_covers_nothing(): void
    {
        $principal = $this->createPrincipal();
        $unit = $this->createUnit();

        $scope = $this->resolve($principal);

        $this->assertFalse($scope->isGlobal());
        $this->assertFalse($scope->covers($unit->id));
    }

    public function test_authorization_denies_when_permission_is_absent_regardless_of_scope(): void
    {
        $principal = $this->createPrincipal();
        $unit = $this->createUnit();
        $this->grantGlobalScope($principal);

        $authorized = app(ScopedAuthorizationChecker::class)
            ->authorize($principal, PermissionCatalog::ORGANIZATION_SCOPES_MANAGE, $unit);

        $this->assertFalse($authorized, 'the principal holds no role granting this permission');
    }

    public function test_authorization_denies_when_permission_present_but_no_scope_grant_exists(): void
    {
        $principal = $this->createPrincipal();
        $role = $this->createRoleWithPermissions([PermissionCatalog::ORGANIZATION_SCOPES_MANAGE]);
        $this->assignRole($principal, $role);
        $unit = $this->createUnit();

        $authorized = app(ScopedAuthorizationChecker::class)
            ->authorize($principal, PermissionCatalog::ORGANIZATION_SCOPES_MANAGE, $unit);

        $this->assertFalse($authorized, 'scope must never default to global coverage');
    }

    public function test_authorization_denies_when_target_is_outside_the_granted_subtree(): void
    {
        $principal = $this->createPrincipal();
        $role = $this->createRoleWithPermissions([PermissionCatalog::ORGANIZATION_SCOPES_MANAGE]);
        $this->assignRole($principal, $role);
        $inScope = $this->createUnit('In scope');
        $outOfScope = $this->createUnit('Out of scope');
        $this->grantUnitScope($principal, $inScope);

        $this->assertTrue(app(ScopedAuthorizationChecker::class)->authorize($principal, PermissionCatalog::ORGANIZATION_SCOPES_MANAGE, $inScope));
        $this->assertFalse(app(ScopedAuthorizationChecker::class)->authorize($principal, PermissionCatalog::ORGANIZATION_SCOPES_MANAGE, $outOfScope));
    }

    public function test_authorization_is_independent_of_which_role_supplied_the_permission(): void
    {
        // Spec §5: two different roles both grant the same permission; scope is evaluated once,
        // independently of which role's grant is inspected.
        $principal = $this->createPrincipal();
        $roleA = $this->createRoleWithPermissions([PermissionCatalog::ORGANIZATION_SCOPES_MANAGE], 'role_a');
        $roleB = $this->createRoleWithPermissions([PermissionCatalog::ORGANIZATION_SCOPES_MANAGE], 'role_b');
        $this->assignRole($principal, $roleA);
        $this->assignRole($principal, $roleB);
        $unit = $this->createUnit();
        $this->grantUnitScope($principal, $unit);

        $this->assertTrue(app(ScopedAuthorizationChecker::class)->authorize($principal, PermissionCatalog::ORGANIZATION_SCOPES_MANAGE, $unit));
    }

    public function test_a_target_unit_that_is_inactive_is_denied_even_though_it_is_in_scope(): void
    {
        // Spec §13 item 3: the target itself is the one place activity status is a hard gate.
        $principal = $this->createPrincipal();
        $role = $this->createRoleWithPermissions([PermissionCatalog::ORGANIZATION_SCOPES_MANAGE]);
        $this->assignRole($principal, $role);
        $unit = $this->createUnit('Inactive target', null, active: false);
        $this->grantUnitScope($principal, $unit);

        $this->assertFalse(app(ScopedAuthorizationChecker::class)->authorize($principal, PermissionCatalog::ORGANIZATION_SCOPES_MANAGE, $unit));
    }

    public function test_reactivating_the_target_restores_authorization_on_the_next_check(): void
    {
        $principal = $this->createPrincipal();
        $role = $this->createRoleWithPermissions([PermissionCatalog::ORGANIZATION_SCOPES_MANAGE]);
        $this->assignRole($principal, $role);
        $unit = $this->createUnit('Target', null, active: false);
        $this->grantUnitScope($principal, $unit);

        $this->assertFalse(app(ScopedAuthorizationChecker::class)->authorize($principal, PermissionCatalog::ORGANIZATION_SCOPES_MANAGE, $unit));

        $unit->forceFill(['is_active' => true])->save();

        $this->assertTrue(app(ScopedAuthorizationChecker::class)->authorize($principal, PermissionCatalog::ORGANIZATION_SCOPES_MANAGE, $unit->refresh()));
    }

    public function test_an_inactive_ancestor_does_not_remove_an_active_descendant_from_scope(): void
    {
        // Spec §13 items 1-2: only the exact target's own activity is a gate; an inactive unit
        // anywhere else in the chain (the grant root, or an intermediate ancestor) does not sever
        // the units below it from being in scope.
        $principal = $this->createPrincipal();
        $root = $this->createUnit('Root', null, active: false);
        $middle = $this->createUnit('Middle', $root->id, active: false);
        $activeLeaf = $this->createUnit('Active leaf', $middle->id, active: true);

        $this->grantUnitScope($principal, $root);
        $scope = $this->resolve($principal);

        $this->assertTrue($scope->covers($activeLeaf->id), 'descendant membership is pure tree structure, unaffected by an inactive ancestor');

        $role = $this->createRoleWithPermissions([PermissionCatalog::ORGANIZATION_SCOPES_MANAGE]);
        $this->assignRole($principal, $role);
        $this->assertTrue(app(ScopedAuthorizationChecker::class)->authorize($principal, PermissionCatalog::ORGANIZATION_SCOPES_MANAGE, $activeLeaf));
    }

    public function test_moving_a_unit_out_of_a_granted_subtree_removes_it_from_scope_on_the_next_check(): void
    {
        $principal = $this->createPrincipal();
        $grantedRoot = $this->createUnit('Granted root');
        $otherRoot = $this->createUnit('Other root');
        $movable = $this->createUnit('Movable', $grantedRoot->id);

        $this->grantUnitScope($principal, $grantedRoot);
        $this->assertTrue($this->resolve($principal)->covers($movable->id));

        app(MoveOrganizationalUnit::class)->handle($movable, $otherRoot->id, 1);

        $this->assertFalse($this->resolve($principal)->covers($movable->id), 'zero writes to organizational_scope_grants — the move alone changes the outcome');
    }

    public function test_moving_a_unit_into_a_granted_subtree_adds_it_to_scope_on_the_next_check(): void
    {
        $principal = $this->createPrincipal();
        $grantedRoot = $this->createUnit('Granted root');
        $otherRoot = $this->createUnit('Other root');
        $movable = $this->createUnit('Movable', $otherRoot->id);

        $this->grantUnitScope($principal, $grantedRoot);
        $this->assertFalse($this->resolve($principal)->covers($movable->id));

        app(MoveOrganizationalUnit::class)->handle($movable, $grantedRoot->id, 1);

        $this->assertTrue($this->resolve($principal)->covers($movable->id));
    }
}
