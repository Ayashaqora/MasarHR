import { useI18n } from '../../i18n/context'
import { describeApiError } from '../../shared/api/errorMessage'
import type { ApiResourceState } from '../../shared/hooks/useApiResource'
import { DateText } from '../../shared/ui/DateText'
import { DataTable } from '../../shared/ui/DataTable'
import { EmptyState } from '../../shared/ui/EmptyState'
import { SectionCard } from '../../shared/ui/SectionCard'
import { StatePanel } from '../../shared/ui/StatePanel'
import type { ActualWorkplace, OrganizationalUnitPeriod } from './api'

/**
 * Workplace tab (spec §S18 §13): the full S11 placement-period stream — the "original" record's own history.
 * The original-vs-actual workplace summary itself is always visible in the current-state cards above the tabs
 * and always comes from the backend's existing ResolveActualWorkplaceForRelationship result (never recomputed
 * in React); `actualWorkplace` is accepted only so a failure to resolve it is surfaced next to the history.
 */
export function Employee360Workplace({
  actualWorkplace,
  placementPeriods,
  unitNames,
}: {
  actualWorkplace: ApiResourceState<ActualWorkplace>
  placementPeriods: ApiResourceState<OrganizationalUnitPeriod[]>
  unitNames: { status: 'loading' | 'ready'; names: Record<string, string> }
}) {
  const { messages } = useI18n()
  const e = messages.employee360

  return (
    <SectionCard level={3} headingId="workplace-placement-history-heading" title={e.placementHistory}>
      {actualWorkplace.status === 'error' ? (
        <StatePanel
          tone="error"
          className="my-0"
          title={actualWorkplace.error.status === 403 ? messages.securityShared.unauthorizedTitle : e.loadFailed}
        >
          {actualWorkplace.error.status === 403
            ? messages.securityShared.unauthorizedDescription
            : describeApiError(actualWorkplace.error, messages)}
        </StatePanel>
      ) : null}
      {placementPeriods.status === 'loading' || unitNames.status === 'loading' ? (
        <StatePanel tone="loading" title={e.loading} className="my-0" />
      ) : placementPeriods.status === 'error' ? (
        <StatePanel tone="error" className="my-0" title={placementPeriods.error.status === 403 ? messages.securityShared.unauthorizedTitle : e.loadFailed}>
          {placementPeriods.error.status === 403
            ? messages.securityShared.unauthorizedDescription
            : describeApiError(placementPeriods.error, messages)}
        </StatePanel>
      ) : placementPeriods.data.length === 0 ? (
        <EmptyState title={e.noPlacementHistory} />
      ) : (
        <DataTable
          caption={e.placementHistory}
          rows={[...placementPeriods.data].sort((a, b) => (b.effective_from ?? '').localeCompare(a.effective_from ?? ''))}
          getKey={(period) => period.id}
          columns={[
            { header: e.unit, cell: (period) => unitNames.names[period.organizational_unit_id] ?? period.organizational_unit_id },
            { header: messages.employees.effectiveFrom, cell: (period) => <DateText value={period.effective_from} /> },
            { header: e.effectiveTo, cell: (period) => <DateText value={period.effective_to} fallback={e.openEnded} /> },
          ]}
        />
      )}
    </SectionCard>
  )
}
