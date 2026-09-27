<?php

namespace App\Modules\Security\Domain;

/**
 * Defines what it means for a principal to be "capable of security administration/recovery" for
 * the last-security-administrator invariant (§17 of the S03 authorization). This is deliberately
 * NOT `role === 'SUPER_ADMIN'` or any other role-name check — it is a fixed set of effective
 * permissions that together let a principal recover the security system: reactivate/disable any
 * account and re-shape who holds which role. A principal with both is "capable"; without either,
 * it is not, no matter what role or role name they hold.
 *
 * This set is intentionally narrower than "every security.* permission": it is exactly the
 * capability needed to recover from a lockout, which is what the invariant protects.
 */
final class SecurityAdministrationCapability
{
    public const REQUIRED_PERMISSIONS = [
        'security.users.status.manage',
        'security.role_assignments.manage',
    ];

    /** @param list<string> $effectivePermissionCodes */
    public static function isCapable(array $effectivePermissionCodes): bool
    {
        return array_diff(self::REQUIRED_PERMISSIONS, $effectivePermissionCodes) === [];
    }
}
