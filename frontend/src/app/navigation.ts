import type { Messages } from '../i18n/messages/types'

export interface NavItem {
  to: string
  labelKey: keyof Messages['nav']
  /** Only the home item is an exact match; the others own their sub-paths. */
  end?: boolean
  /** Only shown to an authenticated principal (see AppShell). */
  requiresAuth?: boolean
}

/**
 * Planned areas. Employees/Organization/Reports/Settings are navigation placeholders only —
 * their functionality belongs to later, separately authorized stages. Security is S03's own area:
 * each of its pages still gates its own content by permission (see PermissionGate), so being
 * authenticated is enough to see the link itself.
 */
export const NAV_ITEMS: readonly NavItem[] = [
  { to: '/', labelKey: 'home', end: true },
  { to: '/employees', labelKey: 'employees' },
  { to: '/organization', labelKey: 'organization' },
  { to: '/reports', labelKey: 'reports' },
  { to: '/security', labelKey: 'security', requiresAuth: true },
  { to: '/settings', labelKey: 'settings' },
]
