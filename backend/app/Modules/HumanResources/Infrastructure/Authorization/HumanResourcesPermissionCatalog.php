<?php

namespace App\Modules\HumanResources\Infrastructure\Authorization;

/**
 * Application-facing constants for the S09 HumanResources-module permission codes. Mirrors, by
 * convention, the five rows seeded by migration
 * 2026_09_29_000004_seed_security_human_resources_permissions — kept in sync by the developer
 * adding a new permission, not by shared code (same convention as PermissionCatalog/
 * OrganizationPermissionCatalog/ReferencePermissionCatalog).
 *
 * Plain RBAC only — no organizational-scope integration (spec §16): neither Person nor Employment
 * Relationship carries an organizational-unit column in S09, so there is no target for
 * ScopedAuthorizationChecker to scope against yet.
 */
final class HumanResourcesPermissionCatalog
{
    public const PERSONS_VIEW = 'hr.persons.view';

    public const PERSONS_CREATE = 'hr.persons.create';

    public const EMPLOYMENT_RELATIONSHIPS_VIEW = 'hr.employment_relationships.view';

    public const EMPLOYMENT_RELATIONSHIPS_CREATE = 'hr.employment_relationships.create';

    public const EMPLOYMENT_RELATIONSHIPS_END = 'hr.employment_relationships.end';

    public const ALL = [
        self::PERSONS_VIEW,
        self::PERSONS_CREATE,
        self::EMPLOYMENT_RELATIONSHIPS_VIEW,
        self::EMPLOYMENT_RELATIONSHIPS_CREATE,
        self::EMPLOYMENT_RELATIONSHIPS_END,
    ];
}
