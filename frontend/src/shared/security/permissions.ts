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
 * on the backend. S18 (Employee 360) is read-only, so only the *_VIEW codes are used by any S18
 * component — the write codes are listed for parity with the backend catalog but nothing in S18
 * checks them (spec §S18 write-action boundary §16/§25).
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
} as const
