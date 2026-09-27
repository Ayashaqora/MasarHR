<?php

namespace App\Modules\Reference\Infrastructure\Authorization;

/**
 * Application-facing constants for the S05 Reference-module permission codes. Mirrors, by
 * convention, the two rows seeded by migration 2026_09_26_000017_seed_security_reference_permissions
 * — kept in sync by the developer adding a new permission, not by shared code (same convention as
 * App\Modules\Security\Infrastructure\Authorization\PermissionCatalog).
 */
final class ReferencePermissionCatalog
{
    public const REFERENCE_VIEW = 'reference.view';

    public const REFERENCE_MANAGE = 'reference.manage';

    public const ALL = [
        self::REFERENCE_VIEW,
        self::REFERENCE_MANAGE,
    ];
}
