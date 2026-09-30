import { useI18n } from '../../i18n/context'
import { describeApiError } from '../../shared/api/errorMessage'
import type { ApiResourceState } from '../../shared/hooks/useApiResource'
import { StatePanel } from '../../shared/ui/StatePanel'
import type { EmploymentStatusDetail, EmploymentStatusPeriod } from './api'

/**
 * Status History tab (spec §S18 §15): renders S10's authoritative employment-status periods
 * as-is. Open (effective_to = null) vs. closed periods are shown from the data itself — no
 * S17-proposed automatic-return behavior is implemented (spec §15 explicit boundary).
 * S33: this tab is PERSISTED HISTORY only. The current status (including the S32-derived on_duty
 * that follows an expired bounded status, which has no row) comes from the backend's effective
 * status on the header/Overview — never from these rows.
 */
export function Employee360StatusHistory({
  statusPeriods,
  statusCatalog,
}: {
  statusPeriods: ApiResourceState<EmploymentStatusPeriod[]> & { retry: () => void }
  statusCatalog: ApiResourceState<EmploymentStatusDetail[]>
}) {
  const { messages, locale } = useI18n()

  if (statusPeriods.status === 'loading' || statusCatalog.status === 'loading') {
    return <StatePanel tone="loading" title={messages.employee360.loading} />
  }

  if (statusPeriods.status === 'error') {
    return (
      <StatePanel
        tone="error"
        title={
          statusPeriods.error.status === 403
            ? messages.securityShared.unauthorizedTitle
            : messages.employee360.loadFailed
        }
        action={
          statusPeriods.error.status === 403 ? undefined : (
            <button type="button" className="button" onClick={statusPeriods.retry}>
              {messages.systemStatus.retry}
            </button>
          )
        }
      >
        {statusPeriods.error.status === 403
          ? messages.securityShared.unauthorizedDescription
          : describeApiError(statusPeriods.error, messages)}
      </StatePanel>
    )
  }

  if (statusPeriods.data.length === 0) {
    return <StatePanel tone="success" title={messages.employee360.noStatusHistory} />
  }

  const catalog = statusCatalog.status === 'success' ? statusCatalog.data : []

  return (
    <>
      <p>{messages.employee360.statusHistoryNote}</p>
    <table className="data-table">
      <caption className="sr-only">{messages.employee360.tabStatusHistory}</caption>
      <thead>
        <tr>
          <th scope="col">{messages.employee360.status}</th>
          <th scope="col">{messages.employees.effectiveFrom}</th>
          <th scope="col">{messages.employee360.effectiveTo}</th>
        </tr>
      </thead>
      <tbody>
        {[...statusPeriods.data]
          .sort((a, b) => (b.effective_from ?? '').localeCompare(a.effective_from ?? ''))
          .map((period) => {
            const detail = catalog.find((item) => item.id === period.status_detail_id)
            return (
              <tr key={period.id}>
                <td>{detail ? (locale === 'ar' ? detail.name_ar : detail.name_en) : period.status_detail_id}</td>
                <td>{period.effective_from ?? '—'}</td>
                <td>{period.effective_to ?? messages.employee360.openEnded}</td>
              </tr>
            )
          })}
      </tbody>
    </table>
    </>
  )
}
