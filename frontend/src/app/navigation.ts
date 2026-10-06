import { Building2, CalendarClock, FileBarChart, House, LayoutDashboard, Settings, ShieldCheck, Users, type LucideIcon } from 'lucide-react'
import { HR_PERMISSIONS, PERMISSIONS, PERMISSIONS_HR_WORKFORCE_ANALYTICS_VIEW } from '../shared/security/permissions'
import type { Messages } from '../i18n/messages/types'

export interface NavItem {
  to: string
  labelKey: keyof Messages['nav']
  icon: LucideIcon
  /** Only the home item is an exact match; the others own their sub-paths. */
  end?: boolean
  /** Only shown to an authenticated principal (see AppShell). */
  requiresAuth?: boolean
  /**
   * When set, the item is shown only if the principal holds at least one of these permissions. This is UX only:
   * the page itself still gates its content (PermissionGate) and the backend re-checks every request.
   */
  anyPermission?: readonly string[]
}

/**
 * Planned areas. Organization/Reports/Settings are navigation placeholders only — their
 * functionality belongs to later, separately authorized stages. Employees is S18's own real,
 * authenticated area (Employee 360 Foundation); Security is S03's own area: each of its pages
 * still gates its own content by permission (see PermissionGate), so being authenticated is
 * enough to see the link itself once the principal holds at least one Security view permission.
 */
export const NAV_ITEMS: readonly NavItem[] = [
  { to: '/', labelKey: 'home', icon: House, end: true },
  {
    to: '/dashboard',
    labelKey: 'dashboard',
    icon: LayoutDashboard,
    requiresAuth: true,
    anyPermission: [PERMISSIONS_HR_WORKFORCE_ANALYTICS_VIEW],
  },
  { to: '/employees', labelKey: 'employees', icon: Users, requiresAuth: true, anyPermission: [HR_PERMISSIONS.personsView] },
  // S47: visible to a principal holding EITHER of the two independent expiry-followup read permissions
  // (docs/expiry-followups-ui-specification.md §5.1). UX only; the backend re-checks each permission on its
  // own endpoint, and the page itself still gates each tab independently (PermissionGate-equivalent).
  {
    to: '/follow-ups',
    labelKey: 'followUps',
    icon: CalendarClock,
    requiresAuth: true,
    anyPermission: [HR_PERMISSIONS.movementExpiryFollowupsView, HR_PERMISSIONS.employmentStatusExpiryFollowupsView],
  },
  { to: '/organization', labelKey: 'organization', icon: Building2 },
  { to: '/reports', labelKey: 'reports', icon: FileBarChart },
  {
    to: '/security',
    labelKey: 'security',
    icon: ShieldCheck,
    requiresAuth: true,
    anyPermission: [PERMISSIONS.usersView, PERMISSIONS.rolesView, PERMISSIONS.permissionsView],
  },
  { to: '/settings', labelKey: 'settings', icon: Settings },
]

/** A nav item is visible when its auth and (optional) permission requirements are met. UX only; the backend re-checks. */
export function isNavItemVisible(item: NavItem, status: string, hasPermission: (code: string) => boolean): boolean {
  if (item.requiresAuth && status !== 'authenticated') {
    return false
  }
  if (item.anyPermission && !item.anyPermission.some((code) => hasPermission(code))) {
    return false
  }
  return true
}

/** Same matching rule as NavLink: the home item is exact, every other item owns its sub-paths. */
export function isNavItemActive(item: NavItem, pathname: string): boolean {
  return item.end ? pathname === item.to : pathname === item.to || pathname.startsWith(`${item.to}/`)
}
