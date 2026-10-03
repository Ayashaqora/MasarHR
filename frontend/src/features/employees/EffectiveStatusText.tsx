import { useI18n } from '../../i18n/context'
import type { ApiResourceState } from '../../shared/hooks/useApiResource'
import { Ltr } from '../../shared/ui/Ltr'
import { StatusBadge } from '../../shared/ui/StatusBadge'
import { statusKeyFromCode } from '../../shared/ui/status'
import type { EffectiveEmploymentStatus, EmploymentStatusDetail } from './api'

/**
 * S33: renders the AUTHORITATIVE effective current status (S32 semantics, resolved by the backend for
 * today's business date). A derived on_duty (the return after an expired bounded status) is labelled
 * as derived — it is not a persisted period and never appears in the status history. This component
 * never inspects persisted history and never decides "current" itself.
 */
export function EffectiveStatusText({
  effectiveStatus,
  statusCatalog,
  withSince = false,
}: {
  effectiveStatus: Extract<ApiResourceState<EffectiveEmploymentStatus>, { status: 'success' }>
  statusCatalog: ApiResourceState<EmploymentStatusDetail[]>
  withSince?: boolean
}) {
  const { messages, locale } = useI18n()
  const status = effectiveStatus.data.status

  if (status === null) {
    return <>{messages.employee360.noEffectiveStatus}</>
  }

  const detail =
    statusCatalog.status === 'success' ? statusCatalog.data.find((item) => item.id === status.status_detail_id) : null
  const label = detail ? (locale === 'ar' ? detail.name_ar : detail.name_en) : status.status_detail_code

  return (
    <span className="inline-flex flex-wrap items-center gap-x-2 gap-y-1">
      <StatusBadge status={statusKeyFromCode(status.status_detail_code)}>{label}</StatusBadge>
      {status.derived ? <span className="text-xs font-normal text-muted-foreground">({messages.employee360.derivedStatusNote})</span> : null}
      {withSince && status.effective_from ? (
        <span className="text-xs font-normal text-muted-foreground">
          {messages.employee360.since} <Ltr>{status.effective_from}</Ltr>
        </span>
      ) : null}
    </span>
  )
}
