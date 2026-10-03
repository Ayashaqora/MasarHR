import { Info, TriangleAlert } from 'lucide-react'
import type { ReactNode } from 'react'
import { Alert, AlertDescription } from '@/components/ui/alert'
import { Badge } from '@/components/ui/badge'
import { useI18n } from '../../i18n/context'
import { SectionCard } from '../../shared/ui/SectionCard'
import { StatCard } from '../../shared/ui/StatCard'
import type { Percentage } from './api'
import { usePercentText } from './usePercentText'
import { kpiContract, type HistoricalSemantics, type KpiId } from './contract'
import { cn } from '@/lib/utils'

/** A bar's visual length is a presentation of the canonical basis points (null → empty), clamped to the track. */
function barWidth(value: Percentage): string {
  const points = value.basis_points ?? 0
  return `${Math.min(100, Math.max(0, points / 100))}%`
}

function useHistoricalLabel() {
  const { messages } = useI18n()

  return (historical: HistoricalSemantics): string | null => {
    switch (historical) {
      case 'CURRENT_RECORDED_ON_HISTORICAL_RERUN':
        return messages.dashboard.historicalRecorded
      case 'TEMPORAL_EXPOSURE':
        return messages.dashboard.historicalTemporal
      case 'EVENT_GRAIN':
        return messages.dashboard.historicalEvent
      case 'HISTORICAL':
        return messages.dashboard.historicalHistorical
      default:
        return null
    }
  }
}

/** One analytic's section: its title, its contract-derived notices and its body. */
export function KpiSection({
  kpi,
  title,
  hint,
  wide,
  children,
}: {
  kpi: KpiId
  title: string
  hint?: string
  /** Spans both columns of the dashboard grid (long lists). */
  wide?: boolean
  children: ReactNode
}) {
  const contract = kpiContract(kpi)
  const historicalLabel = useHistoricalLabel()(contract.historical)

  return (
    <SectionCard
      headingId={`kpi-${kpi}`}
      data-kpi={kpi}
      data-family={contract.family}
      data-historical={contract.historical}
      className={cn('h-full', wide && 'lg:col-span-2')}
      title={
        <>
          {title}
          {historicalLabel ? (
            <Badge variant="secondary" className="font-normal">
              {historicalLabel}
            </Badge>
          ) : null}
        </>
      }
      description={hint}
    >
      {children}
    </SectionCard>
  )
}

export function ScalarCard({ kpi, label, value, hint }: { kpi: KpiId; label: string; value: number; hint: string }) {
  const contract = kpiContract(kpi)

  return <StatCard label={label} value={value} hint={hint} valueTestId={`value-${kpi}`} data-kpi={kpi} data-family={contract.family} />
}

export interface DistributionRow {
  key: string
  label: string
  count: number
  percentage: Percentage
}

/** A MUTUALLY_EXCLUSIVE_DISTRIBUTION: each Person is in exactly one row, so the percentages are shares of one whole. */
export function DistributionList({ rows }: { rows: DistributionRow[] }) {
  const percentText = usePercentText()

  return (
    <ul className="space-y-3" data-family="MUTUALLY_EXCLUSIVE_DISTRIBUTION">
      {rows.map((row) => (
        <li key={row.key} className="space-y-1.5" data-bucket={row.key}>
          <div className="flex items-baseline justify-between gap-3 text-sm">
            <span data-role="label">{row.label}</span>
            <span className="flex shrink-0 items-baseline gap-3 tabular-nums">
              <span data-role="count" className="font-semibold">
                {row.count}
              </span>
              <span data-role="percent" className="text-xs text-muted-foreground">
                {percentText(row.percentage)}
              </span>
            </span>
          </div>
          <div aria-hidden="true" className="h-2 overflow-hidden rounded-full bg-muted">
            <div className="h-full rounded-full bg-chart-2" style={{ inlineSize: barWidth(row.percentage) }} />
          </div>
        </li>
      ))}
    </ul>
  )
}

export interface ExposureRow {
  key: string
  label: string
  personCount: number
  relationshipCount?: number
  share: Percentage
  detail?: ReactNode
}

/** The shared "not a partition" notice for every multi-value exposure section. */
export function ExposureNote() {
  const { messages } = useI18n()

  return (
    <Alert role="note" data-role="exposure-note" className="border-status-information-border bg-status-information text-status-information-foreground">
      <Info aria-hidden="true" />
      <AlertDescription className="text-status-information-foreground">{messages.dashboard.exposureNote}</AlertDescription>
    </Alert>
  )
}

/**
 * A MULTI_VALUE_EXPOSURE: a Person may be in several rows. It is a ranked list with an explicit not-a-partition note: no total
 * row, no sum of the shares, no pie/donut.
 */
export function ExposureList({ rows }: { rows: ExposureRow[] }) {
  const { messages } = useI18n()
  const percentText = usePercentText()

  return (
    <>
      <ExposureNote />
      <ul className="space-y-3" data-family="MULTI_VALUE_EXPOSURE">
        {rows.map((row) => (
          <li key={row.key} className="space-y-1.5" data-bucket={row.key}>
            <div className="flex items-baseline justify-between gap-3 text-sm">
              <span data-role="label">{row.label}</span>
              <span className="flex shrink-0 items-baseline gap-3 tabular-nums">
                <span data-role="count" className="font-semibold">
                  {row.personCount} {messages.dashboard.persons}
                </span>
                <span data-role="percent" className="text-xs text-muted-foreground" title={messages.dashboard.exposureShare}>
                  {messages.dashboard.sharePrefix} {percentText(row.share)}
                </span>
              </span>
            </div>
            <div aria-hidden="true" className="h-2 overflow-hidden rounded-full bg-muted">
              <div className="h-full rounded-full bg-chart-4" style={{ inlineSize: barWidth(row.share) }} />
            </div>
            {row.detail ? <p className="text-xs text-muted-foreground">{row.detail}</p> : null}
          </li>
        ))}
      </ul>
    </>
  )
}

/** A small titled sub-list inside a section (never a heading level that would outrank the section). */
export function SubHeading({ children }: { children: ReactNode }) {
  return <h3 className="pt-2 text-sm font-semibold">{children}</h3>
}

/** Plain count lines (age calculation states, ends by reason, non-determinable workplace). */
export function CountList({ items, testId }: { items: Array<{ key: string; text: ReactNode }>; testId?: string }) {
  return (
    <ul className="space-y-1 text-sm" data-testid={testId}>
      {items.map((item) => (
        <li key={item.key} className="flex items-center gap-2 before:size-1.5 before:rounded-full before:bg-muted-foreground/50 before:content-['']">
          {item.text}
        </li>
      ))}
    </ul>
  )
}

export function QualityFinding({ code, label, counts }: { code: string; label: string; counts: string }) {
  return (
    <li
      data-dq={code}
      className="flex items-start gap-3 rounded-md border border-status-warning-border bg-status-warning p-3 text-status-warning-foreground"
    >
      <TriangleAlert aria-hidden="true" className="mt-0.5 size-4 shrink-0" />
      <span className="flex flex-1 flex-wrap items-baseline justify-between gap-x-4 gap-y-1 text-sm">
        <span className="font-medium">{label}</span>
        <span className="tabular-nums">{counts}</span>
      </span>
    </li>
  )
}
