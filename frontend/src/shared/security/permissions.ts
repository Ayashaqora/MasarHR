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
