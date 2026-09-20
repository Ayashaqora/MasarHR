import { useI18n } from '../i18n/context'
import { SystemStatusCard } from '../features/system-status/SystemStatusCard'
import { PageHeader } from '../shared/ui/PageHeader'

export function HomePage() {
  const { messages } = useI18n()

  return (
    <>
      <PageHeader title={messages.home.title} description={messages.home.intro} />
      <SystemStatusCard />
    </>
  )
}
