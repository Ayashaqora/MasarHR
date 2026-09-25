import { PermissionGate } from '../../features/auth/PermissionGate'
import { PrincipalsTable } from '../../features/security-admin/PrincipalsTable'
import { useI18n } from '../../i18n/context'
import { PERMISSIONS } from '../../shared/security/permissions'
import { PageHeader } from '../../shared/ui/PageHeader'

export function PrincipalsPage() {
  const { messages } = useI18n()

  return (
    <>
      <PageHeader title={messages.securityPrincipals.title} />
      <PermissionGate permission={PERMISSIONS.usersView}>
        <PrincipalsTable />
      </PermissionGate>
    </>
  )
}
