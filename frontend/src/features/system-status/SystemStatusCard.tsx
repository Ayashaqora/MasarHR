import { Activity } from 'lucide-react'
import { useI18n } from '../../i18n/context'
import { describeApiError } from '../../shared/api/errorMessage'
import { RetryButton } from '../../shared/ui/RetryButton'
import { SectionCard } from '../../shared/ui/SectionCard'
import { StatePanel } from '../../shared/ui/StatePanel'
import { useHealthCheck } from './useHealthCheck'

export function SystemStatusCard() {
  const { messages, intlLocale } = useI18n()
  const health = useHealthCheck()
  const text = messages.systemStatus

  return (
    <SectionCard
      headingId="system-status-heading"
      title={
        <>
          <Activity aria-hidden="true" className="size-4 text-muted-foreground" />
          {text.title}
        </>
      }
      className="max-w-2xl"
    >
      {health.status === 'loading' ? <StatePanel tone="loading" title={text.loading} className="my-0" /> : null}

      {health.status === 'success' ? (
        <StatePanel tone="success" title={text.ok} className="my-0">
          {text.checkedAt}:{' '}
          <time dateTime={health.data.timestamp}>
            {new Intl.DateTimeFormat(intlLocale, { dateStyle: 'medium', timeStyle: 'medium' }).format(
              new Date(health.data.timestamp),
            )}
          </time>
        </StatePanel>
      ) : null}

      {health.status === 'error' ? (
        <StatePanel tone="error" title={text.failed} className="my-0" action={<RetryButton onClick={health.retry} />}>
          {describeApiError(health.error, messages)}
        </StatePanel>
      ) : null}
    </SectionCard>
  )
}
