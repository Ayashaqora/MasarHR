import { useI18n } from '../../i18n/context'
import type { ApiResourceState } from '../../shared/hooks/useApiResource'
import { DateText } from '../../shared/ui/DateText'
import type { EffectiveReturnIntention, ReturnIntentionPeriod, ReturnIntentionValue } from './api'
import { Employee360HistorySection } from './Employee360HistorySection'

function useIntentionLabel(): (value: ReturnIntentionValue) => string {
  const { messages } = useI18n()
  return (value) => (value === 'WANTS_TO_RETURN' ? messages.employee360.wantsToReturn : messages.employee360.doesNotWantToReturn)
}

/**
 * S34: the CURRENT Return Intention, as resolved by the backend for today's business date. It is an
 * independent concept from the employment status: absence is shown as "not recorded" and is never
 * rendered as either intention, and nothing here is inferred from the status.
 */
export function ReturnIntentionText({
  effectiveIntention,
}: {
  effectiveIntention: Extract<ApiResourceState<EffectiveReturnIntention>, { status: 'success' }>
}) {
  const { messages } = useI18n()
  const label = useIntentionLabel()
  const current = effectiveIntention.data.return_intention

  if (current === null) {
    return <>{messages.employee360.returnIntentionNotRecorded}</>
  }

  return (
    <span className="inline-flex flex-wrap items-center gap-x-2">
      <span>{label(current.intention)}</span>
      {current.effective_from ? (
        <span className="text-xs font-normal text-muted-foreground">
          {messages.employee360.since} <DateText value={current.effective_from} />
        </span>
      ) : null}
    </span>
  )
}

/** S34: Return Intention HISTORY — persisted periods only, never merged with the status history. */
export function Employee360ReturnIntentionHistory({
  returnIntentionPeriods,
}: {
  returnIntentionPeriods: ApiResourceState<ReturnIntentionPeriod[]> & { retry?: () => void }
}) {
  const { messages } = useI18n()
  const label = useIntentionLabel()
  const e = messages.employee360

  return (
    <Employee360HistorySection
      headingId="return-intention-history-heading"
      title={e.returnIntentionHistory}
      description={e.returnIntentionHistoryNote}
      state={returnIntentionPeriods}
      emptyText={e.noReturnIntentionHistory}
      sortKey={(row) => row.effective_from}
      columns={[
        { header: e.returnIntention, cell: (row) => label(row.intention) },
        { header: messages.employees.effectiveFrom, cell: (row) => <DateText value={row.effective_from} /> },
        { header: e.effectiveTo, cell: (row) => <DateText value={row.effective_to} fallback={e.openEnded} /> },
      ]}
    />
  )
}
