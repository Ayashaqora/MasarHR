import { useI18n } from '../../i18n/context'
import type { ApiResourceState } from '../../shared/hooks/useApiResource'
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
    <>
      {label}
      {status.derived ? ` (${messages.employee360.derivedStatusNote})` : ''}
      {withSince && status.effective_from ? ` — ${messages.employee360.since} ${status.effective_from}` : ''}
    </>
  )
}
