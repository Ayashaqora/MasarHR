<?php

namespace App\Modules\Security\Infrastructure\Authorization;

/**
 * Application-facing constants for the S03 baseline permission codes. Mirrors, by convention, the
 * frozen list in migration 2026_09_23_000007_seed_security_baseline_permissions — that migration
 * intentionally does not reference this class (a released migration must never change behaviour
 * because an application class changed), so the two are kept in sync by the developer adding a new
 * permission, not by shared code.
 */
final class PermissionCatalog
{
    public const USERS_VIEW = 'security.users.view';

    public const USERS_CREATE = 'security.users.create';

    public const USERS_UPDATE = 'security.users.update';

    public const USERS_STATUS_MANAGE = 'security.users.status.manage';

    public const ROLES_VIEW = 'security.roles.view';

    public const ROLES_MANAGE = 'security.roles.manage';

    public const ROLE_ASSIGNMENTS_MANAGE = 'security.role_assignments.manage';

    public const PERMISSIONS_VIEW = 'security.permissions.view';

    /**
     * S08 (docs/organizational-access-scope-specification.md §18): gates both administering
     * (grant/revoke) and reading a principal's organizational scope grants — mirrors the existing
     * ROLE_ASSIGNMENTS_MANAGE precedent, which alone gates both creating and removing a role
     * assignment with no separate "view" permission either.
     */
    public const ORGANIZATION_SCOPES_MANAGE = 'security.organization_scopes.manage';

    public const ALL = [
        self::USERS_VIEW,
        self::USERS_CREATE,
        self::USERS_UPDATE,
        self::USERS_STATUS_MANAGE,
        self::ROLES_VIEW,
        self::ROLES_MANAGE,
        self::ROLE_ASSIGNMENTS_MANAGE,
        self::PERMISSIONS_VIEW,
        self::ORGANIZATION_SCOPES_MANAGE,
    ];
}
