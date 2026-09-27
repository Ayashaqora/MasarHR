import { useI18n } from '../../i18n/context'
import { describeApiError } from '../../shared/api/errorMessage'
import { StatePanel } from '../../shared/ui/StatePanel'
import { useRoles } from './hooks'

export function RolesTable() {
  const { messages } = useI18n()
  const roles = useRoles()

  if (roles.status === 'loading') {
    return <StatePanel tone="loading" title={messages.securityRoles.loading} />
  }

  if (roles.status === 'error') {
    return (
      <StatePanel
        tone="error"
        title={messages.securityRoles.failed}
        action={
          <button type="button" className="button" onClick={roles.retry}>
            {messages.systemStatus.retry}
          </button>
        }
      >
        {describeApiError(roles.error, messages)}
      </StatePanel>
    )
  }

  return (
    <table className="data-table">
      <caption className="sr-only">{messages.securityRoles.title}</caption>
      <thead>
        <tr>
          <th scope="col">{messages.securityRoles.code}</th>
          <th scope="col">{messages.securityRoles.nameAr}</th>
          <th scope="col">{messages.securityRoles.nameEn}</th>
          <th scope="col">{messages.securityRoles.status}</th>
        </tr>
      </thead>
      <tbody>
        {roles.data.length === 0 ? (
          <tr>
            <td colSpan={4}>{messages.securityRoles.empty}</td>
          </tr>
        ) : null}

        {roles.data.map((role) => (
          <tr key={role.id}>
            <td>{role.code}</td>
            <td>{role.name_ar}</td>
            <td>{role.name_en}</td>
            <td>{role.is_active ? messages.securityRoles.active : messages.securityRoles.inactive}</td>
          </tr>
        ))}
      </tbody>
    </table>
  )
}
