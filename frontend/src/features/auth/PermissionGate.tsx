import type { ReactNode } from 'react'
import { useI18n } from '../../i18n/context'
import { StatePanel } from '../../shared/ui/StatePanel'
import { useAuth } from './context'

/**
 * Frontend permission hiding is UX only — the backend is the authorization authority (§15/SEC-04
 * of the S03 authorization) and re-checks the same permission on every request regardless of
 * what this component decided to show.
 */
export function PermissionGate({ permission, children }: { permission: string; children: ReactNode }) {
  const { hasPermission } = useAuth()
  const { messages } = useI18n()

  if (!hasPermission(permission)) {
    return (
      <StatePanel tone="error" title={messages.securityShared.unauthorizedTitle}>
        {messages.securityShared.unauthorizedDescription}
      </StatePanel>
    )
  }

  return <>{children}</>
}
