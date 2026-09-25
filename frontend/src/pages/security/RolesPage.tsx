import { PermissionGate } from '../../features/auth/PermissionGate'
import { RolesTable } from '../../features/security-admin/RolesTable'
import { useI18n } from '../../i18n/context'
import { PERMISSIONS } from '../../shared/security/permissions'
import { PageHeader } from '../../shared/ui/PageHeader'

export function RolesPage() {
  const { messages } = useI18n()

  return (
    <>
      <PageHeader title={messages.securityRoles.title} />
      <PermissionGate permission={PERMISSIONS.rolesView}>
        <RolesTable />
      </PermissionGate>
    </>
  )
}
