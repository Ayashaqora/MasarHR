import { useI18n } from '../../i18n/context'
import { describeApiError } from '../../shared/api/errorMessage'
import { DataTable } from '../../shared/ui/DataTable'
import { Ltr } from '../../shared/ui/Ltr'
import { RetryButton } from '../../shared/ui/RetryButton'
import { StatePanel } from '../../shared/ui/StatePanel'
import { usePermissions } from './hooks'

export function PermissionsTable() {
  const { messages } = useI18n()
  const permissions = usePermissions()

  if (permissions.status === 'loading') {
    return <StatePanel tone="loading" title={messages.securityPermissions.loading} />
  }

  if (permissions.status === 'error') {
    return (
      <StatePanel tone="error" title={messages.securityPermissions.failed} action={<RetryButton onClick={permissions.retry} />}>
        {describeApiError(permissions.error, messages)}
      </StatePanel>
    )
  }

  return (
    <DataTable
      caption={messages.securityPermissions.title}
      emptyText={messages.securityPermissions.empty}
      rows={permissions.data}
      getKey={(permission) => permission.id}
      columns={[
        { header: messages.securityPermissions.code, cell: (permission) => <Ltr>{permission.code}</Ltr> },
        { header: messages.securityPermissions.module, cell: (permission) => permission.module },
        { header: messages.securityPermissions.description, cell: (permission) => permission.description },
      ]}
    />
  )
}
