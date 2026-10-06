import { useState } from 'react'
import { useI18n } from '../../i18n/context'
import { describeApiError } from '../../shared/api/errorMessage'
import { DataTable, type DataTableColumn } from '../../shared/ui/DataTable'
import { DateText } from '../../shared/ui/DateText'
import { Ltr } from '../../shared/ui/Ltr'
import { NativeSelect } from '../../shared/ui/NativeSelect'
import { RetryButton } from '../../shared/ui/RetryButton'
import { StatePanel } from '../../shared/ui/StatePanel'
import { StatusBadge } from '../../shared/ui/StatusBadge'
import type { StatusKey } from '../../shared/ui/status'
import { FOLLOW_UPS_PER_PAGE, type FollowUpStateFilter, type MovementExpiryFollowUp, type MovementSuppressionReason, type MovementType } from './api'
import { FollowUpsPagination } from './FollowUpsPagination'
import { useMovementExpiryFollowUps } from './hooks'

/** Derived `state` -> the existing generic status-badge vocabulary (spec §5.5) — no new token is added. */
const STATE_BADGE: Record<'ACTIONABLE' | 'LAPSED' | 'SUPPRESSED', StatusKey> = {
  ACTIONABLE: 'warning',
  LAPSED: 'information',
  SUPPRESSED: 'inactive',
}

/**
 * S31 movement expiry follow-ups (spec §2). `employment_relationship_id` and `organizational_unit_id`
 * are shown as labelled technical references, never as a name — spec §4 records why no permitted,
 * bounded method exists to resolve either from this surface.
 */
export function MovementFollowUpsTable({ enabled }: { enabled: boolean }) {
  const { messages } = useI18n()
  const f = messages.followUps
  const [state, setState] = useState<FollowUpStateFilter>('ACTIONABLE')
  const [page, setPage] = useState(1)

  const result = useMovementExpiryFollowUps({ state, page, enabled })

  const stateLabel: Record<'ACTIONABLE' | 'LAPSED' | 'SUPPRESSED', string> = {
    ACTIONABLE: f.stateActionable,
    LAPSED: f.stateLapsed,
    SUPPRESSED: f.stateSuppressed,
  }
  const movementTypeLabel: Record<MovementType, string> = {
    FULL_SECONDMENT: f.movementFullSecondment,
    WORKPLACE_ASSIGNMENT: f.movementWorkplaceAssignment,
    PARTIAL_SECONDMENT: f.movementPartialSecondment,
  }
  const reasonLabel: Record<MovementSuppressionReason, string> = {
    TRUNCATED_EARLIER: f.reasonTruncatedEarlier,
    END_DATE_CHANGED: f.reasonEndDateChanged,
    RELATIONSHIP_ENDED: f.reasonRelationshipEnded,
    COVERED_BY_NEWER_MOVEMENT: f.reasonCoveredByNewerMovement,
  }
  const emptyTextByState: Record<FollowUpStateFilter, string> = {
    ACTIONABLE: f.emptyActionable,
    LAPSED: f.emptyLapsed,
    SUPPRESSED: f.emptySuppressed,
    ALL: f.emptyAll,
  }

  const columns: DataTableColumn<MovementExpiryFollowUp>[] = [
    { header: f.columnDueDate, cell: (row) => <DateText value={row.due_date} /> },
    { header: f.columnExpectedEnd, cell: (row) => <DateText value={row.expected_effective_to} /> },
    { header: f.columnMovementType, cell: (row) => movementTypeLabel[row.movement_type] },
    { header: f.columnState, cell: (row) => <StatusBadge status={STATE_BADGE[row.state]}>{stateLabel[row.state]}</StatusBadge> },
    {
      header: f.columnSuppressionReason,
      cell: (row) => (row.state === 'SUPPRESSED' && row.suppression_reason ? reasonLabel[row.suppression_reason] : '—'),
    },
    { header: f.columnRelationshipRef, cell: (row) => <Ltr>{row.employment_relationship_id}</Ltr> },
    { header: f.columnUnitRef, cell: (row) => <Ltr>{row.organizational_unit_id}</Ltr> },
    { header: f.columnCreatedAt, cell: (row) => <DateText value={row.created_at} /> },
    { header: f.columnSuppressedAt, cell: (row) => <DateText value={row.suppressed_at} /> },
  ]

  return (
    <div>
      <div className="mb-3 max-w-xs">
        <label className="mb-1 block text-sm font-medium" htmlFor="movement-followups-state">
          {f.stateLabel}
        </label>
        <NativeSelect
          id="movement-followups-state"
          value={state}
          onChange={(event) => {
            setState(event.target.value as FollowUpStateFilter)
            setPage(1)
          }}
        >
          <option value="ACTIONABLE">{f.stateActionable}</option>
          <option value="LAPSED">{f.stateLapsed}</option>
          <option value="SUPPRESSED">{f.stateSuppressed}</option>
          <option value="ALL">{f.stateAll}</option>
        </NativeSelect>
      </div>

      {result.status === 'loading' ? <StatePanel tone="loading" title={f.loading} className="my-0" /> : null}

      {result.status === 'error' ? (
        result.error.status === 403 ? (
          <StatePanel tone="error" title={messages.securityShared.unauthorizedTitle} className="my-0">
            {messages.securityShared.unauthorizedDescription}
          </StatePanel>
        ) : (
          <StatePanel tone="error" title={f.loadFailed} className="my-0" action={<RetryButton onClick={result.retry} />}>
            {describeApiError(result.error, messages)}
          </StatePanel>
        )
      ) : null}

      {result.status === 'success' ? (
        <>
          <DataTable caption={f.tabMovement} rows={result.data.data} getKey={(row) => row.id} columns={columns} emptyText={emptyTextByState[state]} />
          <FollowUpsPagination
            currentPage={result.data.meta.current_page}
            lastPage={result.data.meta.last_page}
            total={result.data.meta.total}
            pageSize={FOLLOW_UPS_PER_PAGE}
            onPageChange={setPage}
          />
        </>
      ) : null}
    </div>
  )
}
