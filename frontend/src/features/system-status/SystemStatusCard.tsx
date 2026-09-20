import { useI18n } from '../../i18n/context'
import { describeApiError } from '../../shared/api/errorMessage'
import { StatePanel } from '../../shared/ui/StatePanel'
import { useHealthCheck } from './useHealthCheck'

export function SystemStatusCard() {
  const { messages, intlLocale } = useI18n()
  const health = useHealthCheck()
  const text = messages.systemStatus

  return (
    <section className="card" aria-labelledby="system-status-heading">
      <h2 id="system-status-heading" className="card__title">
        {text.title}
      </h2>

      {health.status === 'loading' ? <StatePanel tone="loading" title={text.loading} /> : null}

      {health.status === 'success' ? (
        <StatePanel tone="success" title={text.ok}>
          {text.checkedAt}:{' '}
          <time dateTime={health.data.timestamp}>
            {new Intl.DateTimeFormat(intlLocale, { dateStyle: 'medium', timeStyle: 'medium' }).format(
              new Date(health.data.timestamp),
            )}
          </time>
        </StatePanel>
      ) : null}

      {health.status === 'error' ? (
        <StatePanel
          tone="error"
          title={text.failed}
          action={
            <button type="button" className="button" onClick={health.retry}>
              {text.retry}
            </button>
          }
        >
          {describeApiError(health.error, messages)}
        </StatePanel>
      ) : null}
    </section>
  )
}
