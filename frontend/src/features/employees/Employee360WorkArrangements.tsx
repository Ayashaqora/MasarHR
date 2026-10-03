import { useI18n } from '../../i18n/context'
import type { ApiResourceState } from '../../shared/hooks/useApiResource'
import type { PartialSecondmentPeriod, WorkSchedulePeriod } from './api'
import { DateText } from '../../shared/ui/DateText'
import { Employee360HistorySection } from './Employee360HistorySection'
import { weekdaysLabel } from './weekdays'

/**
 * S33: Partial Secondment (S30) and Work Schedule (S29) HISTORY, exactly as persisted — the weekday
 * allocation and temporal rules are the backend's; nothing is recomputed here.
 */
export function Employee360WorkArrangements({
  partialSecondments,
  workSchedules,
  unitNames,
}: {
  partialSecondments: ApiResourceState<PartialSecondmentPeriod[]> & { retry?: () => void }
  workSchedules: ApiResourceState<WorkSchedulePeriod[]> & { retry?: () => void }
  unitNames: { status: 'loading' | 'ready'; names: Record<string, string> }
}) {
  const { messages } = useI18n()
  const e = messages.employee360

  return (
    <div className="space-y-4">
      <Employee360HistorySection
        headingId="work-arrangements-partial-heading"
        title={e.partialSecondmentHistory}
        description={e.historyTimelineNote}
        state={unitNames.status === 'loading' && partialSecondments.status === 'success' ? { status: 'loading' } : partialSecondments}
        emptyText={e.noPartialSecondmentHistory}
        sortKey={(row) => row.effective_from}
        columns={[
          { header: e.unit, cell: (row) => unitNames.names[row.organizational_unit_id] ?? row.organizational_unit_id },
          { header: e.weekdays, cell: (row) => weekdaysLabel(row.weekdays, messages) },
          { header: messages.employees.effectiveFrom, cell: (row) => <DateText value={row.effective_from} /> },
          { header: e.effectiveTo, cell: (row) => <DateText value={row.effective_to} fallback={e.openEnded} /> },
        ]}
      />
      <Employee360HistorySection
        headingId="work-arrangements-schedule-heading"
        title={e.workScheduleHistory}
        description={e.historyTimelineNote}
        state={workSchedules}
        emptyText={e.noWorkScheduleHistory}
        sortKey={(row) => row.effective_from}
        columns={[
          { header: e.weekdays, cell: (row) => weekdaysLabel(row.weekdays, messages) },
          { header: messages.employees.effectiveFrom, cell: (row) => <DateText value={row.effective_from} /> },
          { header: e.effectiveTo, cell: (row) => <DateText value={row.effective_to} fallback={e.openEnded} /> },
        ]}
      />
    </div>
  )
}
