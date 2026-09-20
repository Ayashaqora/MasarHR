import type { Messages } from '../i18n/messages/types'

export interface NavItem {
  to: string
  labelKey: keyof Messages['nav']
  /** Only the home item is an exact match; the others own their sub-paths. */
  end?: boolean
}

/**
 * Planned areas. These are navigation placeholders only — their functionality
 * belongs to later, separately authorized stages.
 */
export const NAV_ITEMS: readonly NavItem[] = [
  { to: '/', labelKey: 'home', end: true },
  { to: '/employees', labelKey: 'employees' },
  { to: '/organization', labelKey: 'organization' },
  { to: '/reports', labelKey: 'reports' },
  { to: '/settings', labelKey: 'settings' },
]
