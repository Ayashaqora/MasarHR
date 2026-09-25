import { PermissionGate } from '../../features/auth/PermissionGate'
import { PermissionsTable } from '../../features/security-admin/PermissionsTable'
import { useI18n } from '../../i18n/context'
import { PERMISSIONS } from '../../shared/security/permissions'
import { PageHeader } from '../../shared/ui/PageHeader'

export function PermissionsPage() {
  const { messages } = useI18n()

  return (
    <>
      <PageHeader title={messages.securityPermissions.title} />
      <PermissionGate permission={PERMISSIONS.permissionsView}>
        <PermissionsTable />
      </PermissionGate>
    </>
  )
}
