import { useI18n } from '../../i18n/context'
import { describeApiError } from '../../shared/api/errorMessage'
import type { ApiResourceState } from '../../shared/hooks/useApiResource'
import { EmptyState } from '../../shared/ui/EmptyState'
import { RetryButton } from '../../shared/ui/RetryButton'
import { SectionCard } from '../../shared/ui/SectionCard'
import { StatePanel } from '../../shared/ui/StatePanel'
import { StatusBadge } from '../../shared/ui/StatusBadge'
import { statusKeyFromCode } from '../../shared/ui/status'
import { Timeline } from '../../shared/ui/Timeline'
import type { EmploymentStatusDetail, EmploymentStatusPeriod } from './api'

/** Catalog codes whose behavior ends the relationship (seeded by S06): shown as a terminal EVENT, never as a temporary status. */
const TERMINAL_CODES = new Set(['retired', 'resigned', 'contract_ended', 'deceased', 'martyred'])

/**
 * Status History tab (spec §S18 §15): renders S10's authoritative employment-status periods
 * as-is, newest first. Open (effective_to = null) vs. closed periods are shown from the data itself — no
 * S17-proposed automatic-return behavior is implemented (spec §15 explicit boundary).
 * S33: this tab is PERSISTED HISTORY only. The current status (including the S32-derived on_duty
 * that follows an expired bounded status, which has no row) comes from the backend's effective
 * status in the header and current-state cards — never from these rows.
 */
export function Employee360StatusHistory({
  statusPeriods,
  statusCatalog,
}: {
  statusPeriods: ApiResourceState<EmploymentStatusPeriod[]> & { retry: () => void }
  statusCatalog: ApiResourceState<EmploymentStatusDetail[]>
}) {
  const { messages, locale } = useI18n()
  const e = messages.employee360

  if (statusPeriods.status === 'loading' || statusCatalog.status === 'loading') {
    return <StatePanel tone="loading" title={e.loading} />
  }

  if (statusPeriods.status === 'error') {
    return (
      <StatePanel
        tone="error"
        title={statusPeriods.error.status === 403 ? messages.securityShared.unauthorizedTitle : e.loadFailed}
        action={statusPeriods.error.status === 403 ? undefined : <RetryButton onClick={statusPeriods.retry} />}
      >
        {statusPeriods.error.status === 403
          ? messages.securityShared.unauthorizedDescription
          : describeApiError(statusPeriods.error, messages)}
      </StatePanel>
    )
  }

  const catalog = statusCatalog.status === 'success' ? statusCatalog.data : []

  return (
    <SectionCard level={3} headingId="status-history-heading" title={e.tabStatusHistory} description={e.statusHistoryNote}>
      {statusPeriods.data.length === 0 ? (
        <EmptyState title={e.noStatusHistory} />
      ) : (
        <Timeline
          label={e.statusTimelineLabel}
          fromLabel={e.periodFrom}
          toLabel={e.periodTo}
          entries={[...statusPeriods.data]
            .sort((a, b) => (b.effective_from ?? '').localeCompare(a.effective_from ?? ''))
            .map((period) => {
              const detail = catalog.find((item) => item.id === period.status_detail_id)
              const label = detail ? (locale === 'ar' ? detail.name_ar : detail.name_en) : period.status_detail_id
              const terminal = detail ? TERMINAL_CODES.has(detail.code) : false
              return {
                key: period.id,
                title: (
                  <>
                    <StatusBadge status={statusKeyFromCode(detail?.code)}>{label}</StatusBadge>
                    {terminal ? <span className="text-xs font-medium text-muted-foreground">{e.terminalEvent}</span> : null}
                  </>
                ),
                from: period.effective_from ?? '—',
                to: period.effective_to ?? e.openEnded,
              }
            })}
        />
      )}
    </SectionCard>
  )
}
