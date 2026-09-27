<?php

namespace Tests\Unit\Security;

use App\Modules\Security\Domain\SecurityAdministrationCapability;
use PHPUnit\Framework\TestCase;

class SecurityAdministrationCapabilityTest extends TestCase
{
    public function test_capable_with_both_required_permissions(): void
    {
        $this->assertTrue(SecurityAdministrationCapability::isCapable([
            'security.users.status.manage',
            'security.role_assignments.manage',
        ]));
    }

    public function test_capable_with_extra_unrelated_permissions_too(): void
    {
        $this->assertTrue(SecurityAdministrationCapability::isCapable([
            'security.users.status.manage',
            'security.role_assignments.manage',
            'security.users.view',
        ]));
    }

    public function test_not_capable_missing_one_required_permission(): void
    {
        $this->assertFalse(SecurityAdministrationCapability::isCapable(['security.users.status.manage']));
        $this->assertFalse(SecurityAdministrationCapability::isCapable(['security.role_assignments.manage']));
    }

    public function test_not_capable_with_no_permissions(): void
    {
        $this->assertFalse(SecurityAdministrationCapability::isCapable([]));
    }

    public function test_not_capable_with_every_other_permission_except_the_required_two(): void
    {
        $this->assertFalse(SecurityAdministrationCapability::isCapable([
            'security.users.view',
            'security.users.create',
            'security.users.update',
            'security.roles.view',
            'security.roles.manage',
            'security.permissions.view',
        ]));
    }
}
