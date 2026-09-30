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
 * shape S12's own three permissions already established. The two S20 permissions (from
 * 2026_10_06_000002_seed_security_employment_category_period_permissions) are plain RBAC only —
 * the same relationship-level precedent as S10's two status-period permissions
 * (docs/employment-category-history-foundation-specification.md §S20.12, ADR-S20-001 §8). They
 * authorize the temporal employment fact (hr.*), never the ref.employment_categories catalog
 * itself, which remains administered by the Reference module's own reference.* permissions.
 * The two S21 permissions (from 2026_10_07_000002_seed_security_employment_contract_period_permissions)
 * follow exactly the same plain-RBAC, hr.*-not-reference.* precedent for the employment contract
 * fact (docs/employment-contract-foundation-specification.md §S21.13, ADR-S21-001 §10);
 * ref.contract_types stays administered solely by reference.*. The two S22 permissions (from
 * 2026_10_08_000002_seed_security_employment_job_title_period_permissions) follow the same
 * plain-RBAC, hr.*-not-reference.* precedent for the employment job title fact
 * (docs/employment-job-title-history-foundation-specification.md §S22.13, ADR-S22-001 §9);
 * ref.job_titles stays administered solely by reference.*. The two S23 permissions (from
 * 2026_10_09_000002_seed_security_person_qualification_permissions) govern the Person
 * qualification fact with the same Person-level plain RBAC as S09's hr.persons.* permissions
 * (docs/person-qualification-foundation-specification.md §S23.12, ADR-S23-001 §9);
 * ref.academic_degrees / ref.qualification_types stay administered solely by reference.*. The two
 * S26 permissions (from 2026_10_11_000002_seed_security_employment_specialty_period_permissions)
 * follow the S22 plain-RBAC, hr.*-not-reference.* precedent for the employee specialty fact
 * (docs/employee-specialty-history-foundation-specification.md §S26.13, ADR-S26-001);
 * ref.specialties stays administered solely by reference.* (S25).
 */
final class HumanResourcesPermissionCatalog
{
    public const PERSONS_VIEW = 'hr.persons.view';

    public const PERSONS_CREATE = 'hr.persons.create';

    /**
     * S24 (docs/person-profile-foundation-specification.md §S24.12): explicit write authority for
     * a Person's profile. Reads keep hr.persons.view; creation keeps hr.persons.create.
     */
    public const PERSONS_UPDATE_PROFILE = 'hr.persons.update_profile';

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

    /** S20 (docs/employment-category-history-foundation-specification.md §S20.12). */
    public const EMPLOYMENT_CATEGORY_PERIODS_VIEW = 'hr.employment_category_periods.view';

    public const EMPLOYMENT_CATEGORY_PERIODS_RECORD = 'hr.employment_category_periods.record';

    /** S21 (docs/employment-contract-foundation-specification.md §S21.13). */
    public const EMPLOYMENT_CONTRACT_PERIODS_VIEW = 'hr.employment_contract_periods.view';

    public const EMPLOYMENT_CONTRACT_PERIODS_RECORD = 'hr.employment_contract_periods.record';

    /** S22 (docs/employment-job-title-history-foundation-specification.md §S22.13). */
    public const EMPLOYMENT_JOB_TITLE_PERIODS_VIEW = 'hr.employment_job_title_periods.view';

    public const EMPLOYMENT_JOB_TITLE_PERIODS_RECORD = 'hr.employment_job_title_periods.record';

    /** S23 (docs/person-qualification-foundation-specification.md §S23.12). */
    public const PERSON_QUALIFICATIONS_VIEW = 'hr.person_qualifications.view';

    public const PERSON_QUALIFICATIONS_RECORD = 'hr.person_qualifications.record';

    /** S26 (docs/employee-specialty-history-foundation-specification.md §S26.13). */
    public const EMPLOYMENT_SPECIALTY_PERIODS_VIEW = 'hr.employment_specialty_periods.view';

    public const EMPLOYMENT_SPECIALTY_PERIODS_RECORD = 'hr.employment_specialty_periods.record';

    /** S29 (docs/work-schedule-foundation-specification.md §S29.12). */
    public const WORK_SCHEDULE_PERIODS_VIEW = 'hr.work_schedule_periods.view';

    public const WORK_SCHEDULE_PERIODS_RECORD = 'hr.work_schedule_periods.record';

    /** S30 (docs/partial-secondment-foundation-specification.md §S30.15). */
    public const PARTIAL_SECONDMENT_PERIODS_VIEW = 'hr.partial_secondment_periods.view';

    public const PARTIAL_SECONDMENT_PERIODS_RECORD = 'hr.partial_secondment_periods.record';

    /** S31 (docs/movement-expiry-followup-foundation-specification.md §S31.16). Read-only: follow-ups are written by the system scanner. */
    public const MOVEMENT_EXPIRY_FOLLOWUPS_VIEW = 'hr.movement_expiry_followups.view';

    /** S38 (docs/employment-status-expiry-followup-specification.md §S38.15). Dedicated, plain RBAC (no organizational scope); read-only — follow-ups are written by the system scanner. */
    public const EMPLOYMENT_STATUS_EXPIRY_FOLLOWUPS_VIEW = 'hr.employment_status_expiry_followups.view';

    /** S39 (docs/monthly-not-on-duty-report-foundation-specification.md §S39.10). Dedicated, plain RBAC (no organizational scope); read-only. */
    public const MONTHLY_NOT_ON_DUTY_VIEW = 'hr.monthly_not_on_duty.view';

    /** S34: Return Intention — independent of the employment-status permissions. */
    public const RETURN_INTENTION_PERIODS_VIEW = 'hr.return_intention_periods.view';

    public const RETURN_INTENTION_PERIODS_RECORD = 'hr.return_intention_periods.record';

    public const ALL = [
        self::PERSONS_VIEW,
        self::PERSONS_CREATE,
        self::PERSONS_UPDATE_PROFILE,
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
        self::EMPLOYMENT_CATEGORY_PERIODS_VIEW,
        self::EMPLOYMENT_CATEGORY_PERIODS_RECORD,
        self::EMPLOYMENT_CONTRACT_PERIODS_VIEW,
        self::EMPLOYMENT_CONTRACT_PERIODS_RECORD,
        self::EMPLOYMENT_JOB_TITLE_PERIODS_VIEW,
        self::EMPLOYMENT_JOB_TITLE_PERIODS_RECORD,
        self::PERSON_QUALIFICATIONS_VIEW,
        self::PERSON_QUALIFICATIONS_RECORD,
        self::EMPLOYMENT_SPECIALTY_PERIODS_VIEW,
        self::EMPLOYMENT_SPECIALTY_PERIODS_RECORD,
        self::WORK_SCHEDULE_PERIODS_VIEW,
        self::WORK_SCHEDULE_PERIODS_RECORD,
        self::PARTIAL_SECONDMENT_PERIODS_VIEW,
        self::PARTIAL_SECONDMENT_PERIODS_RECORD,
        self::MOVEMENT_EXPIRY_FOLLOWUPS_VIEW,
        self::EMPLOYMENT_STATUS_EXPIRY_FOLLOWUPS_VIEW,
        self::MONTHLY_NOT_ON_DUTY_VIEW,
        self::RETURN_INTENTION_PERIODS_VIEW,
        self::RETURN_INTENTION_PERIODS_RECORD,
    ];
}
