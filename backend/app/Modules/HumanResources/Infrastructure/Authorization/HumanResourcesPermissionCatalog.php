<?php

namespace App\Modules\HumanResources\Infrastructure\Authorization;

/**
 * Application-facing constants for the HumanResources-module permission codes. The first five
 * are S09's (mirrored, by convention, from migration
 * 2026_09_29_000004_seed_security_human_resources_permissions); the next two are S10's (from
 * 2026_09_30_000002_seed_security_employment_status_period_permissions); the last two are S11's
 * (from 2026_10_01_000002_seed_security_organizational_placement_period_permissions) — kept in
 * sync by the developer adding a new permission, not by shared code (same convention as
 * PermissionCatalog/OrganizationPermissionCatalog/ReferencePermissionCatalog).
 *
 * Plain RBAC only for S09/S10 (S09 spec §16, S10 spec §13) — neither Person, Employment
 * Relationship, nor Employment Status Period carries an organizational-unit column, so there was
 * no target for ScopedAuthorizationChecker to scope against yet. S11's two permissions are the
 * first in this catalog that additionally require S08 organizational scope to cover the target
 * unit (docs/organizational-placement-foundation-specification.md §10) — that composition happens
 * in OrganizationalPlacementPeriodController via ScopedAuthorizationChecker, not here; this class
 * still only lists the RBAC permission codes themselves.
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

    public const ORGANIZATIONAL_PLACEMENT_PERIODS_VIEW = 'hr.organizational_placement_periods.view';

    public const ORGANIZATIONAL_PLACEMENT_PERIODS_RECORD = 'hr.organizational_placement_periods.record';

    public const ALL = [
        self::PERSONS_VIEW,
        self::PERSONS_CREATE,
        self::EMPLOYMENT_RELATIONSHIPS_VIEW,
        self::EMPLOYMENT_RELATIONSHIPS_CREATE,
        self::EMPLOYMENT_RELATIONSHIPS_END,
        self::EMPLOYMENT_STATUS_PERIODS_VIEW,
        self::EMPLOYMENT_STATUS_PERIODS_RECORD,
        self::ORGANIZATIONAL_PLACEMENT_PERIODS_VIEW,
        self::ORGANIZATIONAL_PLACEMENT_PERIODS_RECORD,
    ];
}
