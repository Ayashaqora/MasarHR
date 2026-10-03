import { useI18n } from '../../i18n/context'
import { describeApiError } from '../../shared/api/errorMessage'
import { DataTable } from '../../shared/ui/DataTable'
import { Ltr } from '../../shared/ui/Ltr'
import { RetryButton } from '../../shared/ui/RetryButton'
import { StatePanel } from '../../shared/ui/StatePanel'
import { StatusBadge } from '../../shared/ui/StatusBadge'
import { useRoles } from './hooks'

export function RolesTable() {
  const { messages } = useI18n()
  const roles = useRoles()

  if (roles.status === 'loading') {
    return <StatePanel tone="loading" title={messages.securityRoles.loading} />
  }

  if (roles.status === 'error') {
    return (
      <StatePanel tone="error" title={messages.securityRoles.failed} action={<RetryButton onClick={roles.retry} />}>
        {describeApiError(roles.error, messages)}
      </StatePanel>
    )
  }

  return (
    <DataTable
      caption={messages.securityRoles.title}
      emptyText={messages.securityRoles.empty}
      rows={roles.data}
      getKey={(role) => role.id}
      columns={[
        { header: messages.securityRoles.code, cell: (role) => <Ltr>{role.code}</Ltr> },
        { header: messages.securityRoles.nameAr, cell: (role) => role.name_ar },
        { header: messages.securityRoles.nameEn, cell: (role) => <span dir="ltr">{role.name_en}</span> },
        {
          header: messages.securityRoles.status,
          cell: (role) =>
            role.is_active ? (
              <StatusBadge status="active">{messages.securityRoles.active}</StatusBadge>
            ) : (
              <StatusBadge status="inactive">{messages.securityRoles.inactive}</StatusBadge>
            ),
        },
      ]}
    />
  )
}
