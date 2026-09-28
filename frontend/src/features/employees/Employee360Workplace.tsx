import { useI18n } from '../../i18n/context'
import { describeApiError } from '../../shared/api/errorMessage'
import type { ApiResourceState } from '../../shared/hooks/useApiResource'
import { StatePanel } from '../../shared/ui/StatePanel'
import type { ActualWorkplace, OrganizationalUnitPeriod } from './api'

const SOURCE_LABEL_KEY = {
  secondment: 'sourceSecondment',
  assignment: 'sourceAssignment',
  placement: 'sourcePlacement',
} as const

/**
 * A period's `effective_from` is required by every backend command that creates one, so it should
 * never actually be null — but OrganizationalUnitPeriodResource's `?->toDateString()` chaining
 * leaves the type technically nullable, and sorting a null as `''` would make it look earliest of
 * all (an adversarial-review finding against an earlier draft). Sorting an unknown date last
 * instead means a period with a genuinely-missing date is never wrongly picked as "the original".
 */
const UNKNOWN_DATE_SORTS_LAST = '9999-99-99'

/**
 * Workplace tab (spec §S18 §13): visually distinguishes original organizational placement from
 * the current actual workplace — never computed here, always read from the backend's existing
 * ResolveActualWorkplaceForRelationship result (spec §13, never re-implemented in React) — plus
 * the full S11 placement-period stream (the "original" record's own history).
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

  const originalPlacement =
    placementPeriods.status === 'success' && placementPeriods.data.length > 0
      ? [...placementPeriods.data].sort(
          (a, b) => (a.effective_from ?? UNKNOWN_DATE_SORTS_LAST).localeCompare(b.effective_from ?? UNKNOWN_DATE_SORTS_LAST),
        )[0]
      : null

  return (
    <div className="tab-panel-content">
      <section className="card" aria-labelledby="workplace-summary-heading">
        <h3 id="workplace-summary-heading" className="card__title">
          {messages.employee360.workplaceSummary}
        </h3>
        {actualWorkplace.status === 'loading' || placementPeriods.status === 'loading' || unitNames.status === 'loading' ? (
          <StatePanel tone="loading" title={messages.employee360.loading} />
        ) : actualWorkplace.status === 'error' ? (
          <StatePanel
            tone="error"
            title={
              actualWorkplace.error.status === 403
                ? messages.securityShared.unauthorizedTitle
                : messages.employee360.loadFailed
            }
          >
            {actualWorkplace.error.status === 403
              ? messages.securityShared.unauthorizedDescription
              : describeApiError(actualWorkplace.error, messages)}
          </StatePanel>
        ) : (
          <dl className="description-list">
            <div className="description-list__row">
              <dt>{messages.employee360.originalWorkplace}</dt>
              <dd>
                {originalPlacement
                  ? (unitNames.names[originalPlacement.organizational_unit_id] ?? originalPlacement.organizational_unit_id)
                  : messages.employee360.noOriginalWorkplace}
              </dd>
            </div>
            <div className="description-list__row">
              <dt>{messages.employee360.actualWorkplace}</dt>
              <dd>
                {actualWorkplace.data.organizational_unit_id
                  ? `${unitNames.names[actualWorkplace.data.organizational_unit_id] ?? actualWorkplace.data.organizational_unit_id} (${
                      messages.employee360[SOURCE_LABEL_KEY[actualWorkplace.data.source ?? 'placement']]
                    })`
                  : messages.employee360.noActualWorkplace}
              </dd>
            </div>
          </dl>
        )}
      </section>

      <section className="card" aria-labelledby="workplace-placement-history-heading">
        <h3 id="workplace-placement-history-heading" className="card__title">
          {messages.employee360.placementHistory}
        </h3>
        {placementPeriods.status === 'loading' ? (
          <StatePanel tone="loading" title={messages.employee360.loading} />
        ) : placementPeriods.status === 'error' ? (
          <StatePanel
            tone="error"
            title={
              placementPeriods.error.status === 403
                ? messages.securityShared.unauthorizedTitle
                : messages.employee360.loadFailed
            }
          >
            {placementPeriods.error.status === 403
              ? messages.securityShared.unauthorizedDescription
              : describeApiError(placementPeriods.error, messages)}
          </StatePanel>
        ) : placementPeriods.data.length === 0 ? (
          <StatePanel tone="success" title={messages.employee360.noPlacementHistory} />
        ) : (
          <table className="data-table">
            <caption className="sr-only">{messages.employee360.placementHistory}</caption>
            <thead>
              <tr>
                <th scope="col">{messages.employee360.unit}</th>
                <th scope="col">{messages.employees.effectiveFrom}</th>
                <th scope="col">{messages.employee360.effectiveTo}</th>
              </tr>
            </thead>
            <tbody>
              {[...placementPeriods.data]
                .sort((a, b) => (b.effective_from ?? '').localeCompare(a.effective_from ?? ''))
                .map((period) => (
                  <tr key={period.id}>
                    <td>{unitNames.names[period.organizational_unit_id] ?? period.organizational_unit_id}</td>
                    <td>{period.effective_from ?? '—'}</td>
                    <td>{period.effective_to ?? messages.employee360.openEnded}</td>
                  </tr>
                ))}
            </tbody>
          </table>
        )}
      </section>
    </div>
  )
}
