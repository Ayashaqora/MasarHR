<?php

namespace App\Modules\HumanResources\Infrastructure\Authorization;

/**
 * Application-facing constants for the HumanResources-module permission codes. The first five
 * are S09's (mirrored, by convention, from migration
 * 2026_09_29_000004_seed_security_human_resources_permissions); the last two are S10's (from
 * 2026_09_30_000002_seed_security_employment_status_period_permissions) — kept in sync by the
 * developer adding a new permission, not by shared code (same convention as PermissionCatalog/
 * OrganizationPermissionCatalog/ReferencePermissionCatalog).
 *
 * Plain RBAC only — no organizational-scope integration (S09 spec §16, S10 spec §13): neither
 * Person, Employment Relationship, nor Employment Status Period carries an organizational-unit
 * column, so there is no target for ScopedAuthorizationChecker to scope against yet.
 */
final class HumanResourcesPermissionCatalog
{
    public const PERSONS_VIEW = 'hr.persons.view';

    public const PERSONS_CREATE = 'hr.persons.create';

    public const EMPLOYMENT_RELATIONSHIPS_VIEW = 'hr.employment_relationships.view';

    public const EMPLOYMENT_RELATIONSHIPS_CREATE = 'hr.employment_relationships.create';

    public const EMPLOYMENT_RELATIONSHIPS_END = 'hr.employment_relationships.end';

    public const EMPLOYMENT_STATUS_PERIODS_VIEW = 'hr.employment_status_periods.view';

    public const EMPLOYMENT_STATUS_PERIODS_RECORD = 'hr.employment_status_periods.record';

    public const ALL = [
        self::PERSONS_VIEW,
        self::PERSONS_CREATE,
        self::EMPLOYMENT_RELATIONSHIPS_VIEW,
        self::EMPLOYMENT_RELATIONSHIPS_CREATE,
        self::EMPLOYMENT_RELATIONSHIPS_END,
        self::EMPLOYMENT_STATUS_PERIODS_VIEW,
        self::EMPLOYMENT_STATUS_PERIODS_RECORD,
    ];
}
