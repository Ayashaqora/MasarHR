<?php

namespace App\Modules\Organization\Infrastructure\Authorization;

/**
 * Application-facing constants for the S07 Organization-module permission codes. Mirrors, by
 * convention, the two rows seeded by migration
 * 2026_09_26_000031_seed_security_organization_permissions — kept in sync by the developer adding
 * a new permission, not by shared code (same convention as
 * App\Modules\Security\Infrastructure\Authorization\PermissionCatalog and
 * App\Modules\Reference\Infrastructure\Authorization\ReferencePermissionCatalog).
 */
final class OrganizationPermissionCatalog
{
    public const ORGANIZATION_VIEW = 'organization.view';

    public const ORGANIZATION_MANAGE = 'organization.manage';

    public const ALL = [
        self::ORGANIZATION_VIEW,
        self::ORGANIZATION_MANAGE,
    ];
}
