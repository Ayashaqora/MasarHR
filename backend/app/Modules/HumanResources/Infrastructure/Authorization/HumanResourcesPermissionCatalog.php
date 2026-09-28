<?php

namespace App\Modules\HumanResources\Infrastructure\Authorization;

/**
 * Application-facing constants for the HumanResources-module permission codes. The first five
 * are S09's (mirrored, by convention, from migration
 * 2026_09_29_000004_seed_security_human_resources_permissions); the next two are S10's (from
 * 2026_09_30_000002_seed_security_employment_status_period_permissions); the next two are S11's
 * (from 2026_10_01_000002_seed_security_organizational_placement_period_permissions); the last
 * three are S12's (from 2026_10_02_000002_seed_security_full_secondment_period_permissions) —
 * kept in sync by the developer adding a new permission, not by shared code (same convention as
 * PermissionCatalog/OrganizationPermissionCatalog/ReferencePermissionCatalog).
 *
 * Plain RBAC only for S09/S10 (S09 spec §16, S10 spec §13) — neither Person, Employment
 * Relationship, nor Employment Status Period carries an organizational-unit column, so there was
 * no target for ScopedAuthorizationChecker to scope against yet. S11's two permissions were the
 * first in this catalog that additionally require S08 organizational scope to cover the target
 * unit; S12's three permissions require it against up to TWO target units per operation
 * (docs/full-secondment-foundation-specification.md §12.1) — that composition happens in
 * FullSecondmentPeriodController via ScopedAuthorizationChecker, not here; this class still only
 * lists the RBAC permission codes themselves. The next permission is S14's (from
 * 2026_10_04_000002_seed_security_transfer_permission) — it requires S08 scope against up to
 * FOUR target units as of S16 (destination, source placement, source secondment if closed,
 * source assignment if closed; docs/transfer-foundation-specification.md §12.1,
 * docs/workplace-assignment-foundation-specification.md §S16.14), the same "call the checker more
 * than once, never modify it" composition TransferController itself performs. The final three
 * permissions are S16's (from
 * 2026_10_05_000003_seed_security_workplace_assignment_period_permissions) — they require S08
 * scope against up to TWO target units per operation
 * (docs/workplace-assignment-foundation-specification.md §S16.14), the identical composition
 * shape S12's own three permissions already established.
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

    public const FULL_SECONDMENT_PERIODS_VIEW = 'hr.full_secondment_periods.view';

    public const FULL_SECONDMENT_PERIODS_START = 'hr.full_secondment_periods.start';

    public const FULL_SECONDMENT_PERIODS_END = 'hr.full_secondment_periods.end';

    /**
     * S14 (docs/transfer-foundation-specification.md §12). Named on the existing
     * `hr.employment_relationships.*` family — `create`/`end`/`transfer` — rather than a new
     * `hr.transfers.*` family, because TransferEmployee is a lifecycle action on the Employment
     * Relationship aggregate itself (it writes no new resource of its own, §16), not the creation
     * of a distinct "transfer" resource.
     */
    public const EMPLOYMENT_RELATIONSHIPS_TRANSFER = 'hr.employment_relationships.transfer';

    /** S16 (docs/workplace-assignment-foundation-specification.md §S16.14). */
    public const WORKPLACE_ASSIGNMENT_PERIODS_VIEW = 'hr.workplace_assignment_periods.view';

    public const WORKPLACE_ASSIGNMENT_PERIODS_START = 'hr.workplace_assignment_periods.start';

    public const WORKPLACE_ASSIGNMENT_PERIODS_END = 'hr.workplace_assignment_periods.end';

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
        self::FULL_SECONDMENT_PERIODS_VIEW,
        self::FULL_SECONDMENT_PERIODS_START,
        self::FULL_SECONDMENT_PERIODS_END,
        self::EMPLOYMENT_RELATIONSHIPS_TRANSFER,
        self::WORKPLACE_ASSIGNMENT_PERIODS_VIEW,
        self::WORKPLACE_ASSIGNMENT_PERIODS_START,
        self::WORKPLACE_ASSIGNMENT_PERIODS_END,
    ];
}
