import { ArrowLeftRight, Building2, ClipboardList, type LucideIcon } from 'lucide-react'
import { Badge } from '@/components/ui/badge'
import { useI18n } from '../../i18n/context'
import { describeApiError } from '../../shared/api/errorMessage'
import type { ApiResourceState } from '../../shared/hooks/useApiResource'
import { EmptyState } from '../../shared/ui/EmptyState'
import { SectionCard } from '../../shared/ui/SectionCard'
import { StatePanel } from '../../shared/ui/StatePanel'
import { Timeline } from '../../shared/ui/Timeline'
import type { OrganizationalUnitPeriod } from './api'

type MovementKind = 'placement' | 'secondment' | 'assignment'

interface MovementEntry {
  key: string
  kind: MovementKind
  period: OrganizationalUnitPeriod
}

const KIND_ICON: Record<MovementKind, LucideIcon> = {
  placement: Building2,
  secondment: ArrowLeftRight,
  assignment: ClipboardList,
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
 * Movement is deliberately styled as a neutral tag, never as a status badge: it is independent of employment status.
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
  const e = messages.employee360

  const sources: Array<{ kind: MovementKind; state: ApiResourceState<OrganizationalUnitPeriod[]> }> = [
    { kind: 'placement', state: placementPeriods },
    { kind: 'secondment', state: secondmentPeriods },
    { kind: 'assignment', state: assignmentPeriods },
  ]

  const anyLoading = sources.some(({ state }) => state.status === 'loading') || unitNames.status === 'loading'
  const allErrored = sources.every(({ state }) => state.status === 'error')

  if (anyLoading) {
    return <StatePanel tone="loading" title={e.loading} />
  }

  if (allErrored) {
    const first = sources[0]!.state
    return (
      <StatePanel
        tone="error"
        title={first.status === 'error' && first.error.status === 403 ? messages.securityShared.unauthorizedTitle : e.loadFailed}
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
  const sorted = [...entries].sort((a, b) => (b.period.effective_from ?? '').localeCompare(a.period.effective_from ?? ''))

  const kindLabel: Record<MovementKind, string> = {
    placement: e.sourcePlacement,
    secondment: e.sourceSecondment,
    assignment: e.sourceAssignment,
  }

  return (
    <div className="space-y-4">
      {partialFailures.length > 0 ? (
        <StatePanel tone="error" title={e.partialMovementDataTitle} className="my-0">
          {e.partialMovementDataDescription}
        </StatePanel>
      ) : null}

      <SectionCard level={3} headingId="movement-timeline-heading" title={e.tabMovementTimeline}>
        {sorted.length === 0 ? (
          <EmptyState title={e.noMovements} />
        ) : (
          <Timeline
            label={e.movementTimelineLabel}
            fromLabel={e.periodFrom}
            toLabel={e.periodTo}
            entries={sorted.map((entry) => {
              const Icon = KIND_ICON[entry.kind]
              return {
                key: entry.key,
                title: (
                  <Badge variant="secondary" className="gap-1.5">
                    <Icon aria-hidden="true" className="size-3.5" />
                    {kindLabel[entry.kind]}
                  </Badge>
                ),
                detail: unitNames.names[entry.period.organizational_unit_id] ?? entry.period.organizational_unit_id,
                from: entry.period.effective_from ?? '—',
                to: entry.period.effective_to ?? e.openEnded,
              }
            })}
          />
        )}
      </SectionCard>
    </div>
  )
}
