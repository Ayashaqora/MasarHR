<?php

namespace Tests\Feature\Security;

use App\Modules\Security\Application\Commands\RemoveRoleFromPrincipal;
use App\Modules\Security\Application\Security\SecurityAdministrationGuard;
use App\Modules\Security\Domain\Exceptions\LastSecurityAdministratorException;
use App\Modules\Security\Domain\SecurityAdministrationCapability;
use App\Modules\Security\Infrastructure\Authorization\EffectivePermissionsResolver;
use App\Modules\Security\Infrastructure\Authorization\PermissionCatalog;

/**
 * Proves spec §15's reasoning, not just asserts it: S08 introduces no new lockout risk, so no new
 * SecurityAdministrationGuard-style invariant is needed. security.organization_scopes.manage is
 * deliberately absent from SecurityAdministrationCapability::REQUIRED_PERMISSIONS
 * (ADR-S08-002), and granting/revoking scope never touches principal_roles/role_permissions at all,
 * so it can never change who is "capable of security administration."
 */
class OrganizationalScopeAdministrativeSafetyTest extends OrganizationalScopeTestCase
{
    public function test_the_scope_administration_permission_is_not_part_of_the_last_security_admin_invariant(): void
    {
        $this->assertNotContains(
            PermissionCatalog::ORGANIZATION_SCOPES_MANAGE,
            SecurityAdministrationCapability::REQUIRED_PERMISSIONS,
        );
    }

    public function test_revoking_the_only_scope_administrators_permission_is_allowed_and_not_blocked_by_any_invariant(): void
    {
        $principal = $this->createPrincipal();
        $role = $this->createRoleWithPermissions([PermissionCatalog::ORGANIZATION_SCOPES_MANAGE]);
        $this->assignRole($principal, $role);

        // No LastSecurityAdministratorException, no guard, no lock — this permission carries no
        // special protection, exactly like organization.manage or reference.manage today.
        app(RemoveRoleFromPrincipal::class)->handle($principal, $role);

        $resolver = app(EffectivePermissionsResolver::class);
        $this->assertFalse($resolver->has($principal->refresh(), PermissionCatalog::ORGANIZATION_SCOPES_MANAGE));
    }

    public function test_granting_and_revoking_scope_grants_never_changes_security_administration_capability(): void
    {
        $administrator = $this->createSecurityAdministrator();
        $target = $this->createPrincipal();
        $unit = $this->createUnit();

        $resolver = app(EffectivePermissionsResolver::class);
        $capableBefore = SecurityAdministrationCapability::isCapable($resolver->resolve($administrator));

        $grant = $this->grantUnitScope($target, $unit);
        $this->grantGlobalScope($administrator);

        $this->assertSame($capableBefore, SecurityAdministrationCapability::isCapable($resolver->resolve($administrator)), 'granting scope must never change security-administration capability');

        $grant->delete();

        $this->assertSame($capableBefore, SecurityAdministrationCapability::isCapable($resolver->resolve($administrator)), 'revoking scope must never change security-administration capability');
    }

    public function test_s03_last_security_administrator_invariant_still_protects_the_two_original_permissions(): void
    {
        // Regression proof that S08 did not weaken §12 of the S03 authorization: removing the last
        // security administrator's role is still rejected, exactly as it was before S08 existed.
        $administrator = $this->createSecurityAdministrator();
        $role = $administrator->roleAssignments()->first()->role;

        $this->expectException(LastSecurityAdministratorException::class);

        app(SecurityAdministrationGuard::class)->run(
            fn () => app(RemoveRoleFromPrincipal::class)->handle($administrator, $role),
        );
    }
}
