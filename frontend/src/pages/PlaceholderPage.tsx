import { useI18n } from '../i18n/context'
import type { Messages } from '../i18n/messages/types'
import { PageHeader } from '../shared/ui/PageHeader'

/** Navigation placeholder for a planned area. It intentionally shows no data and no controls. */
export function PlaceholderPage({ navKey }: { navKey: keyof Messages['nav'] }) {
  const { messages } = useI18n()

  return <PageHeader title={messages.nav[navKey]} description={messages.placeholder.notImplemented} />
}
