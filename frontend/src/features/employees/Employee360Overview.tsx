import { useI18n } from '../../i18n/context'
import { describeApiError } from '../../shared/api/errorMessage'
import type { ApiResourceState } from '../../shared/hooks/useApiResource'
import { StatePanel } from '../../shared/ui/StatePanel'
import type { ActualWorkplace, EffectiveEmploymentStatus, EffectiveReturnIntention, EmploymentStatusDetail, OrganizationalUnitPeriod } from './api'
import { EffectiveStatusText } from './EffectiveStatusText'
import { ReturnIntentionText } from './Employee360ReturnIntention'

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
 * Overview tab (spec §S18 §12.A; S33): the CURRENT STATE — the backend's effective status (S32) — and current and current vs. original workplace, each with
 * its effective date — the same facts the header shows at a glance, expanded with dates and the
 * resolver's own disclosed source (spec §13: actual workplace always comes from the existing
 * authoritative ResolveActualWorkplaceForRelationship result, never recomputed here).
 */
export function Employee360Overview({
  effectiveStatus,
  effectiveIntention,
  statusCatalog,
  actualWorkplace,
  placementPeriods,
  unitNames,
}: {
  effectiveStatus: ApiResourceState<EffectiveEmploymentStatus>
  effectiveIntention: ApiResourceState<EffectiveReturnIntention>
  statusCatalog: ApiResourceState<EmploymentStatusDetail[]>
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
      <section className="card" aria-labelledby="overview-status-heading">
        <h3 id="overview-status-heading" className="card__title">
          {messages.employee360.currentStatus}
        </h3>
        {effectiveStatus.status === 'loading' || statusCatalog.status === 'loading' ? (
          <StatePanel tone="loading" title={messages.employee360.loading} />
        ) : effectiveStatus.status === 'error' ? (
          <StatePanel
            tone="error"
            title={
              effectiveStatus.error.status === 403
                ? messages.securityShared.unauthorizedTitle
                : messages.employee360.loadFailed
            }
          >
            {effectiveStatus.error.status === 403
              ? messages.securityShared.unauthorizedDescription
              : describeApiError(effectiveStatus.error, messages)}
          </StatePanel>
        ) : (
          <>
            <p>
              <EffectiveStatusText effectiveStatus={effectiveStatus} statusCatalog={statusCatalog} withSince />
            </p>
            <p>
              {messages.employee360.asOf} {effectiveStatus.data.as_of}
            </p>
          </>
        )}
      </section>

      <section className="card" aria-labelledby="overview-return-intention-heading">
        <h3 id="overview-return-intention-heading" className="card__title">
          {messages.employee360.returnIntention}
        </h3>
        {effectiveIntention.status === 'loading' ? (
          <StatePanel tone="loading" title={messages.employee360.loading} />
        ) : effectiveIntention.status === 'error' ? (
          <StatePanel
            tone="error"
            title={
              effectiveIntention.error.status === 403
                ? messages.securityShared.unauthorizedTitle
                : messages.employee360.loadFailed
            }
          >
            {effectiveIntention.error.status === 403
              ? messages.securityShared.unauthorizedDescription
              : describeApiError(effectiveIntention.error, messages)}
          </StatePanel>
        ) : (
          <p>
            <ReturnIntentionText effectiveIntention={effectiveIntention} />
          </p>
        )}
      </section>

      <section className="card" aria-labelledby="overview-workplace-heading">
        <h3 id="overview-workplace-heading" className="card__title">
          {messages.employee360.workplaceSummary}
        </h3>
        {actualWorkplace.status === 'loading' || unitNames.status === 'loading' ? (
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
    </div>
  )
}
