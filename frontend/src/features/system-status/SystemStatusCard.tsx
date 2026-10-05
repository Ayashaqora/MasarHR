import { Activity } from 'lucide-react'
import { useI18n } from '../../i18n/context'
import { describeApiError } from '../../shared/api/errorMessage'
import { formatDateOnly } from '../../shared/lib/date'
import { Ltr } from '../../shared/ui/Ltr'
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
            {/* Only the date part changes format (dd/MM/yyyy, UI-DATE-001 item 3); the time and time zone are
                preserved exactly as before, via the same locale-aware Intl formatting, now scoped to time only. */}
            <Ltr>{formatDateOnly(new Date(health.data.timestamp))}</Ltr>
            {', '}
            {new Intl.DateTimeFormat(intlLocale, { timeStyle: 'medium' }).format(new Date(health.data.timestamp))}
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
