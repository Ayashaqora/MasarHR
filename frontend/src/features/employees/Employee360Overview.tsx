import { Briefcase, MapPin, Undo2 } from 'lucide-react'
import { useI18n } from '../../i18n/context'
import { describeApiError } from '../../shared/api/errorMessage'
import type { ApiResourceState } from '../../shared/hooks/useApiResource'
import { DefinitionItem, DefinitionList } from '../../shared/ui/DefinitionList'
import { SectionCard } from '../../shared/ui/SectionCard'
import { StatePanel } from '../../shared/ui/StatePanel'
import type { ActualWorkplace, EffectiveEmploymentStatus, EffectiveReturnIntention, EmploymentStatusDetail, OrganizationalUnitPeriod } from './api'
import { EffectiveStatusText } from './EffectiveStatusText'
import { ReturnIntentionText } from './Employee360ReturnIntention'
import { Ltr } from '../../shared/ui/Ltr'

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

function stateError(error: { status?: number | undefined }, messages: ReturnType<typeof useI18n>['messages']) {
  return {
    title: error.status === 403 ? messages.securityShared.unauthorizedTitle : messages.employee360.loadFailed,
    body: error.status === 403 ? messages.securityShared.unauthorizedDescription : null,
  }
}

/**
 * The CURRENT STATE (spec §S18 §12.A; S33), always visible above the history tabs: the backend's effective
 * status (S32), the independent Return Intention (S34), and original vs. actual workplace. The three are
 * separate cards on purpose — status, return intention and movement never merge into one field. The actual
 * workplace always comes from the existing authoritative ResolveActualWorkplaceForRelationship result.
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
    <div className="space-y-2">
      <div>
        <h2 className="text-base font-semibold">{messages.employee360.currentStateTitle}</h2>
        <p className="text-xs text-muted-foreground">{messages.employee360.currentStateNote}</p>
      </div>
      <div className="grid gap-4 lg:grid-cols-3">
        <SectionCard
          level={3}
          headingId="overview-status-heading"
          title={
            <>
              <Briefcase aria-hidden="true" className="size-4 text-muted-foreground" />
              {messages.employee360.currentStatus}
            </>
          }
        >
          {effectiveStatus.status === 'loading' || statusCatalog.status === 'loading' ? (
            <StatePanel tone="loading" title={messages.employee360.loading} className="my-0" />
          ) : effectiveStatus.status === 'error' ? (
            <StatePanel tone="error" className="my-0" title={stateError(effectiveStatus.error, messages).title}>
              {stateError(effectiveStatus.error, messages).body ?? describeApiError(effectiveStatus.error, messages)}
            </StatePanel>
          ) : (
            <div className="space-y-2">
              <p>
                <EffectiveStatusText effectiveStatus={effectiveStatus} statusCatalog={statusCatalog} withSince />
              </p>
              <p className="text-xs text-muted-foreground">
                {messages.employee360.asOf} <Ltr>{effectiveStatus.data.as_of}</Ltr>
              </p>
            </div>
          )}
        </SectionCard>

        <SectionCard
          level={3}
          headingId="overview-return-intention-heading"
          title={
            <>
              <Undo2 aria-hidden="true" className="size-4 text-muted-foreground" />
              {messages.employee360.returnIntention}
            </>
          }
        >
          {effectiveIntention.status === 'loading' ? (
            <StatePanel tone="loading" title={messages.employee360.loading} className="my-0" />
          ) : effectiveIntention.status === 'error' ? (
            <StatePanel tone="error" className="my-0" title={stateError(effectiveIntention.error, messages).title}>
              {stateError(effectiveIntention.error, messages).body ?? describeApiError(effectiveIntention.error, messages)}
            </StatePanel>
          ) : (
            <p className="text-sm font-medium">
              <ReturnIntentionText effectiveIntention={effectiveIntention} />
            </p>
          )}
        </SectionCard>

        <SectionCard
          level={3}
          headingId="overview-workplace-heading"
          title={
            <>
              <MapPin aria-hidden="true" className="size-4 text-muted-foreground" />
              {messages.employee360.workplaceSummary}
            </>
          }
        >
          {actualWorkplace.status === 'loading' || unitNames.status === 'loading' ? (
            <StatePanel tone="loading" title={messages.employee360.loading} className="my-0" />
          ) : actualWorkplace.status === 'error' ? (
            <StatePanel tone="error" className="my-0" title={stateError(actualWorkplace.error, messages).title}>
              {stateError(actualWorkplace.error, messages).body ?? describeApiError(actualWorkplace.error, messages)}
            </StatePanel>
          ) : (
            <DefinitionList className="sm:grid-cols-1">
              <DefinitionItem label={messages.employee360.originalWorkplace}>
                {originalPlacement
                  ? (unitNames.names[originalPlacement.organizational_unit_id] ?? originalPlacement.organizational_unit_id)
                  : messages.employee360.noOriginalWorkplace}
              </DefinitionItem>
              <DefinitionItem label={messages.employee360.actualWorkplace}>
                {actualWorkplace.data.organizational_unit_id
                  ? `${unitNames.names[actualWorkplace.data.organizational_unit_id] ?? actualWorkplace.data.organizational_unit_id} (${
                      messages.employee360[SOURCE_LABEL_KEY[actualWorkplace.data.source ?? 'placement']]
                    })`
                  : messages.employee360.noActualWorkplace}
              </DefinitionItem>
            </DefinitionList>
          )}
        </SectionCard>
      </div>
    </div>
  )
}
