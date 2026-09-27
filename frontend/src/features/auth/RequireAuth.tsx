import { Navigate, Outlet, useLocation } from 'react-router'
import { useI18n } from '../../i18n/context'
import { StatePanel } from '../../shared/ui/StatePanel'
import { useAuth } from './context'

/**
 * Route guard for the whole Security section: only checks *authentication*. Which *permission*
 * a given page needs is different per page (viewing users vs. viewing roles vs. viewing
 * permissions), so that check lives on each page instead (see PermissionGate).
 */
export function RequireAuth() {
  const { status } = useAuth()
  const { messages } = useI18n()
  const location = useLocation()

  if (status === 'bootstrapping') {
    return <StatePanel tone="loading" title={messages.auth.checkingSession} />
  }

  if (status === 'unauthenticated') {
    return <Navigate to="/login" replace state={{ from: location.pathname }} />
  }

  return <Outlet />
}
