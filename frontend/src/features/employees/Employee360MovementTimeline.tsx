import { useI18n } from '../../i18n/context'
import { describeApiError } from '../../shared/api/errorMessage'
import type { ApiResourceState } from '../../shared/hooks/useApiResource'
import { StatePanel } from '../../shared/ui/StatePanel'
import type { OrganizationalUnitPeriod } from './api'

type MovementKind = 'placement' | 'secondment' | 'assignment'

interface MovementEntry {
  key: string
  kind: MovementKind
  period: OrganizationalUnitPeriod
}

/**
 * Movement Timeline tab (spec §S18 §14): merges the three authoritative, independently-fetched
 * period streams (S11 placement, S12 full secondment, S16 workplace assignment) into one
 * chronological list, sorted only by effective_from — a display concern, not a business-rule
 * computation. It never synthesizes a "transfer" entry: TransferEmployee persists no event of its
 * own (docs/transfer-foundation-specification.md §16) — a transfer is entirely represented by the
 * S11 placement-period boundary it writes (and the secondment/assignment period it may close as a
 * consequence), both of which already appear here under their own real type. Reconstructing which
 * placement-period boundary was specifically caused by a transfer call (vs. a direct placement
 * record) is not derivable from period data alone and is not attempted (spec §14: "If transfer
 * has no dedicated persisted event... perform that composition in the backend read model, not by
 * guessing in React" — S18 chose not to add that backend composition; see spec §S18 gap matrix).
 */
export function Employee360MovementTimeline({
  placementPeriods,
  secondmentPeriods,
  assignmentPeriods,
  unitNames,
}: {
  placementPeriods: ApiResourceState<OrganizationalUnitPeriod[]>
  secondmentPeriods: ApiResourceState<OrganizationalUnitPeriod[]>
  assignmentPeriods: ApiResourceState<OrganizationalUnitPeriod[]>
  unitNames: { status: 'loading' | 'ready'; names: Record<string, string> }
}) {
  const { messages } = useI18n()

  const sources: Array<{ kind: MovementKind; state: ApiResourceState<OrganizationalUnitPeriod[]> }> = [
    { kind: 'placement', state: placementPeriods },
    { kind: 'secondment', state: secondmentPeriods },
    { kind: 'assignment', state: assignmentPeriods },
  ]

  const anyLoading = sources.some(({ state }) => state.status === 'loading') || unitNames.status === 'loading'
  const allErrored = sources.every(({ state }) => state.status === 'error')

  if (anyLoading) {
    return <StatePanel tone="loading" title={messages.employee360.loading} />
  }

  if (allErrored) {
    const first = sources[0]!.state
    return (
      <StatePanel
        tone="error"
        title={first.status === 'error' && first.error.status === 403 ? messages.securityShared.unauthorizedTitle : messages.employee360.loadFailed}
      >
        {first.status === 'error' && first.error.status === 403
          ? messages.securityShared.unauthorizedDescription
          : first.status === 'error'
            ? describeApiError(first.error, messages)
            : null}
      </StatePanel>
    )
  }

  const entries: MovementEntry[] = []
  for (const { kind, state } of sources) {
    if (state.status === 'success') {
      for (const period of state.data) {
        entries.push({ key: `${kind}:${period.id}`, kind, period })
      }
    }
  }

  const partialFailures = sources.filter(({ state }) => state.status === 'error')

  if (entries.length === 0) {
    return <StatePanel tone="success" title={messages.employee360.noMovements} />
  }

  const sorted = [...entries].sort(
    (a, b) => (b.period.effective_from ?? '').localeCompare(a.period.effective_from ?? ''),
  )

  const kindLabel: Record<MovementKind, string> = {
    placement: messages.employee360.sourcePlacement,
    secondment: messages.employee360.sourceSecondment,
    assignment: messages.employee360.sourceAssignment,
  }

  return (
    <div className="tab-panel-content">
      {partialFailures.length > 0 ? (
        <StatePanel tone="error" title={messages.employee360.partialMovementDataTitle}>
          {messages.employee360.partialMovementDataDescription}
        </StatePanel>
      ) : null}

      <table className="data-table">
        <caption className="sr-only">{messages.employee360.tabMovementTimeline}</caption>
        <thead>
          <tr>
            <th scope="col">{messages.employee360.movementType}</th>
            <th scope="col">{messages.employee360.unit}</th>
            <th scope="col">{messages.employees.effectiveFrom}</th>
            <th scope="col">{messages.employee360.effectiveTo}</th>
          </tr>
        </thead>
        <tbody>
          {sorted.map((entry) => (
            <tr key={entry.key}>
              <td>{kindLabel[entry.kind]}</td>
              <td>{unitNames.names[entry.period.organizational_unit_id] ?? entry.period.organizational_unit_id}</td>
              <td>{entry.period.effective_from ?? '—'}</td>
              <td>{entry.period.effective_to ?? messages.employee360.openEnded}</td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  )
}
