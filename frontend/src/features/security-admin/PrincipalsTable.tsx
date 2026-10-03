import { useState } from 'react'
import { useAuth } from '../auth/context'
import { useI18n } from '../../i18n/context'
import { ApiError } from '../../shared/api'
import { describeApiError } from '../../shared/api/errorMessage'
import { PERMISSIONS } from '../../shared/security/permissions'
import { DataTable, type DataTableColumn } from '../../shared/ui/DataTable'
import { Ltr } from '../../shared/ui/Ltr'
import { RetryButton } from '../../shared/ui/RetryButton'
import { StatePanel } from '../../shared/ui/StatePanel'
import { StatusBadge } from '../../shared/ui/StatusBadge'
import {
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
  AlertDialogTrigger,
} from '@/components/ui/alert-dialog'
import { Button } from '@/components/ui/button'
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
        action={<RetryButton onClick={principals.retry} />}
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

  const columns: DataTableColumn<PrincipalSummary>[] = [
    { header: messages.securityPrincipals.username, cell: (row) => <Ltr>{row.username}</Ltr> },
    { header: messages.securityPrincipals.displayName, cell: (row) => row.display_name },
    {
      header: messages.securityPrincipals.status,
      cell: (row) =>
        row.status === 'ACTIVE' ? (
          <StatusBadge status="active">{messages.securityPrincipals.statusActive}</StatusBadge>
        ) : (
          <StatusBadge status="inactive">{messages.securityPrincipals.statusDisabled}</StatusBadge>
        ),
    },
  ]

  if (canManageStatus) {
    columns.push({
      header: messages.securityPrincipals.actions,
      cell: (row) => (
        <div className="space-y-1">
          {row.status === 'ACTIVE' ? (
            <AlertDialog>
              <AlertDialogTrigger asChild>
                <Button type="button" size="sm" variant="outline" disabled={pendingId === row.id}>
                  {messages.securityPrincipals.disable}
                </Button>
              </AlertDialogTrigger>
              <AlertDialogContent>
                <AlertDialogHeader>
                  <AlertDialogTitle>{messages.securityPrincipals.confirmDisableTitle}</AlertDialogTitle>
                  <AlertDialogDescription>{messages.securityPrincipals.confirmDisableDescription}</AlertDialogDescription>
                </AlertDialogHeader>
                <AlertDialogFooter>
                  <AlertDialogCancel>{messages.securityPrincipals.cancel}</AlertDialogCancel>
                  <AlertDialogAction
                    className="bg-destructive text-white hover:bg-destructive/90"
                    onClick={() => {
                      void toggleStatus(row)
                    }}
                  >
                    {messages.securityPrincipals.confirmDisable}
                  </AlertDialogAction>
                </AlertDialogFooter>
              </AlertDialogContent>
            </AlertDialog>
          ) : (
            <Button
              type="button"
              size="sm"
              variant="outline"
              disabled={pendingId === row.id}
              onClick={() => {
                void toggleStatus(row)
              }}
            >
              {messages.securityPrincipals.enable}
            </Button>
          )}
          {rowError && rowError.id === row.id ? (
            <p role="alert" className="text-sm font-medium text-destructive">
              {rowError.message}
            </p>
          ) : null}
        </div>
      ),
    })
  }

  return (
    <DataTable
      caption={messages.securityPrincipals.title}
      emptyText={messages.securityPrincipals.empty}
      rows={principals.data}
      getKey={(row) => row.id}
      columns={columns}
    />
  )
}
