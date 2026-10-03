import { NavLink } from 'react-router'
import { buttonVariants } from '@/components/ui/button'
import { cn } from '@/lib/utils'
import { useI18n } from '../../i18n/context'
import { PERMISSIONS } from '../../shared/security/permissions'
import { useAuth } from '../auth/context'

/** Switches between the three existing Security pages; each link is shown only if its page's view permission is held. */
export function SecuritySubNav() {
  const { messages } = useI18n()
  const { hasPermission } = useAuth()
  const items = [
    { to: '/security/principals', label: messages.securityPrincipals.title, permission: PERMISSIONS.usersView },
    { to: '/security/roles', label: messages.securityRoles.title, permission: PERMISSIONS.rolesView },
    { to: '/security/permissions', label: messages.securityPermissions.title, permission: PERMISSIONS.permissionsView },
  ].filter((item) => hasPermission(item.permission))

  if (items.length < 2) {
    return null
  }

  return (
    <nav aria-label={messages.securityShared.sectionNavigation} className="mb-4 flex flex-wrap gap-2">
      {items.map((item) => (
        <NavLink
          key={item.to}
          to={item.to}
          className={({ isActive }) => cn(buttonVariants({ variant: isActive ? 'default' : 'outline', size: 'sm' }))}
        >
          {item.label}
        </NavLink>
      ))}
    </nav>
  )
}
