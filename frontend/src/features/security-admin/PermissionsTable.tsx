import { useI18n } from '../../i18n/context'
import { describeApiError } from '../../shared/api/errorMessage'
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
      <StatePanel
        tone="error"
        title={messages.securityPermissions.failed}
        action={
          <button type="button" className="button" onClick={permissions.retry}>
            {messages.systemStatus.retry}
          </button>
        }
      >
        {describeApiError(permissions.error, messages)}
      </StatePanel>
    )
  }

  return (
    <table className="data-table">
      <caption className="sr-only">{messages.securityPermissions.title}</caption>
      <thead>
        <tr>
          <th scope="col">{messages.securityPermissions.code}</th>
          <th scope="col">{messages.securityPermissions.module}</th>
          <th scope="col">{messages.securityPermissions.description}</th>
        </tr>
      </thead>
      <tbody>
        {permissions.data.length === 0 ? (
          <tr>
            <td colSpan={3}>{messages.securityPermissions.empty}</td>
          </tr>
        ) : null}

        {permissions.data.map((permission) => (
          <tr key={permission.id}>
            <td>{permission.code}</td>
            <td>{permission.module}</td>
            <td>{permission.description}</td>
          </tr>
        ))}
      </tbody>
    </table>
  )
}
