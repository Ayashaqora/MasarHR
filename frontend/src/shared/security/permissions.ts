/**
 * Mirrors the backend's Security-module permission codes (see
 * App\Modules\Security\Infrastructure\Authorization\PermissionCatalog on the backend). These are
 * used only to decide what the UI shows — the backend re-checks every one of them on every
 * request and is the sole authority (§15/SEC-04 of the S03 authorization).
 */
export const PERMISSIONS = {
  usersView: 'security.users.view',
  usersCreate: 'security.users.create',
  usersUpdate: 'security.users.update',
  usersStatusManage: 'security.users.status.manage',
  rolesView: 'security.roles.view',
  rolesManage: 'security.roles.manage',
  roleAssignmentsManage: 'security.role_assignments.manage',
  permissionsView: 'security.permissions.view',
} as const

/**
 * Mirrors App\Modules\HumanResources\Infrastructure\Authorization\HumanResourcesPermissionCatalog
 * on the backend (exact codes, none invented). S18 used only the *_VIEW codes; S46 (Employee 360
 * operations) also checks the write codes below to decide which actions to offer. Read permission never
 * implies write permission, and the backend re-checks every code (plus organizational scope) on every request.
 */
export const HR_PERMISSIONS = {
  personsView: 'hr.persons.view',
  personsCreate: 'hr.persons.create',
  employmentRelationshipsView: 'hr.employment_relationships.view',
  employmentRelationshipsCreate: 'hr.employment_relationships.create',
  employmentRelationshipsEnd: 'hr.employment_relationships.end',
  employmentStatusPeriodsView: 'hr.employment_status_periods.view',
  employmentStatusPeriodsRecord: 'hr.employment_status_periods.record',
  organizationalPlacementPeriodsView: 'hr.organizational_placement_periods.view',
  organizationalPlacementPeriodsRecord: 'hr.organizational_placement_periods.record',
  fullSecondmentPeriodsView: 'hr.full_secondment_periods.view',
  fullSecondmentPeriodsStart: 'hr.full_secondment_periods.start',
  fullSecondmentPeriodsEnd: 'hr.full_secondment_periods.end',
  employmentRelationshipsTransfer: 'hr.employment_relationships.transfer',
  workplaceAssignmentPeriodsView: 'hr.workplace_assignment_periods.view',
  workplaceAssignmentPeriodsStart: 'hr.workplace_assignment_periods.start',
  workplaceAssignmentPeriodsEnd: 'hr.workplace_assignment_periods.end',
  partialSecondmentPeriodsView: 'hr.partial_secondment_periods.view',
  partialSecondmentPeriodsRecord: 'hr.partial_secondment_periods.record',
  workSchedulePeriodsView: 'hr.work_schedule_periods.view',
  workSchedulePeriodsRecord: 'hr.work_schedule_periods.record',
  returnIntentionPeriodsView: 'hr.return_intention_periods.view',
  returnIntentionPeriodsRecord: 'hr.return_intention_periods.record',
} as const

/**
 * S44/S45: the dedicated read permission of the Workforce Analytics foundation (plain RBAC, granted to no role by default). The
 * aggregate Dashboard reuses it; the backend re-checks it on every request.
 */
export const PERMISSIONS_HR_WORKFORCE_ANALYTICS_VIEW = 'hr.workforce_analytics.view'
