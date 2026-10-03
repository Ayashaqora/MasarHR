import { RotateCw } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { useI18n } from '../../i18n/context'

/** The one retry affordance used by every load-error surface. */
export function RetryButton({ onClick }: { onClick: () => void }) {
  const { messages } = useI18n()

  return (
    <Button type="button" variant="outline" size="sm" onClick={onClick}>
      <RotateCw aria-hidden="true" />
      {messages.systemStatus.retry}
    </Button>
  )
}
