import { useState } from 'react'
import { useAuth } from '../auth/context'
import { useI18n } from '../../i18n/context'
import { ApiError } from '../../shared/api'
import { describeApiError } from '../../shared/api/errorMessage'
import { PERMISSIONS } from '../../shared/security/permissions'
import { StatePanel } from '../../shared/ui/StatePanel'
import { updatePrincipalStatus, type PrincipalSummary } from './api'
import { usePrincipals } from './hooks'

export function PrincipalsTable() {
  const { messages } = useI18n()
  const { hasPermission, sessionExpired } = useAuth()
  const principals = usePrincipals()
  const [pendingId, setPendingId] = useState<string | null>(null)
  const [rowError, setRowError] = useState<{ id: string; message: string } | null>(null)

  if (principals.status === 'loading') {
    return <StatePanel tone="loading" title={messages.securityPrincipals.loading} />
  }

  if (principals.status === 'error') {
    return (
      <StatePanel
        tone="error"
        title={messages.securityPrincipals.failed}
        action={
          <button type="button" className="button" onClick={principals.retry}>
            {messages.systemStatus.retry}
          </button>
        }
      >
        {describeApiError(principals.error, messages)}
      </StatePanel>
    )
  }

  const canManageStatus = hasPermission(PERMISSIONS.usersStatusManage)

  async function toggleStatus(row: PrincipalSummary) {
    const nextStatus = row.status === 'ACTIVE' ? 'DISABLED' : 'ACTIVE'
    setPendingId(row.id)
    setRowError(null)

    try {
      await updatePrincipalStatus(row.id, nextStatus, row.version)
      principals.retry()
    } catch (caught: unknown) {
      if (caught instanceof ApiError && caught.status === 401) {
        sessionExpired()
        return
      }
      // §17: this can be rejected as a 409 (a stale row, or the last-security-administrator
      // invariant). Either way the safe, honest response is "reload and try again" — never a
      // message that reveals which specific protection fired.
      const message =
        caught instanceof ApiError && caught.status === 409
          ? messages.securityPrincipals.conflict
          : messages.securityPrincipals.actionFailed
      setRowError({ id: row.id, message })
    } finally {
      setPendingId(null)
    }
  }

  const columnCount = canManageStatus ? 4 : 3

  return (
    <table className="data-table">
      <caption className="sr-only">{messages.securityPrincipals.title}</caption>
      <thead>
        <tr>
          <th scope="col">{messages.securityPrincipals.username}</th>
          <th scope="col">{messages.securityPrincipals.displayName}</th>
          <th scope="col">{messages.securityPrincipals.status}</th>
          {canManageStatus ? <th scope="col">{messages.securityPrincipals.actions}</th> : null}
        </tr>
      </thead>
      <tbody>
        {principals.data.length === 0 ? (
          <tr>
            <td colSpan={columnCount}>{messages.securityPrincipals.empty}</td>
          </tr>
        ) : null}

        {principals.data.map((row) => (
          <tr key={row.id}>
            <td>{row.username}</td>
            <td>{row.display_name}</td>
            <td>
              {row.status === 'ACTIVE'
                ? messages.securityPrincipals.statusActive
                : messages.securityPrincipals.statusDisabled}
            </td>
            {canManageStatus ? (
              <td>
                <button
                  type="button"
                  className="button button--small"
                  disabled={pendingId === row.id}
                  onClick={() => {
                    void toggleStatus(row)
                  }}
                >
                  {row.status === 'ACTIVE' ? messages.securityPrincipals.disable : messages.securityPrincipals.enable}
                </button>
                {rowError && rowError.id === row.id ? (
                  <p role="alert" className="field-error">
                    {rowError.message}
                  </p>
                ) : null}
              </td>
            ) : null}
          </tr>
        ))}
      </tbody>
    </table>
  )
}
