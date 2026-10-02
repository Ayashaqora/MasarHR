import type { ReactNode } from 'react'
import { useI18n } from '../../i18n/context'
import type { Percentage } from './api'
import { usePercentText } from './usePercentText'
import { kpiContract, type HistoricalSemantics, type KpiId } from './contract'

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
  children,
}: {
  kpi: KpiId
  title: string
  hint?: string
  children: ReactNode
}) {
  const contract = kpiContract(kpi)
  const historicalLabel = useHistoricalLabel()(contract.historical)
  const headingId = `kpi-${kpi}`

  return (
    <section
      className="card dashboard-section"
      aria-labelledby={headingId}
      data-kpi={kpi}
      data-family={contract.family}
      data-historical={contract.historical}
    >
      <h2 id={headingId} className="card__title">
        {title}
        {historicalLabel ? <span className="tag">{historicalLabel}</span> : null}
      </h2>
      {hint ? <p className="dashboard-section__hint">{hint}</p> : null}
      {children}
    </section>
  )
}

export function ScalarCard({
  kpi,
  label,
  value,
  hint,
}: {
  kpi: KpiId
  label: string
  value: number
  hint: string
}) {
  const contract = kpiContract(kpi)

  return (
    <div className="kpi" data-kpi={kpi} data-family={contract.family}>
      <p className="kpi__label">{label}</p>
      <p className="kpi__value" data-testid={`value-${kpi}`}>
        {value}
      </p>
      <p className="kpi__hint">{hint}</p>
    </div>
  )
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
    <ul className="distribution" data-family="MUTUALLY_EXCLUSIVE_DISTRIBUTION">
      {rows.map((row) => (
        <li key={row.key} className="distribution__row" data-bucket={row.key}>
          <span className="distribution__label">{row.label}</span>
          <span className="distribution__count">{row.count}</span>
          <span className="distribution__percent">{percentText(row.percentage)}</span>
          <span className="bar" aria-hidden="true">
            <span className="bar__fill" style={{ inlineSize: barWidth(row.percentage) }} />
          </span>
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

/**
 * A MULTI_VALUE_EXPOSURE: a Person may be in several rows. It is a ranked list with an explicit not-a-partition note: no total
 * row, no sum of the shares, no pie/donut.
 */
export function ExposureList({ rows }: { rows: ExposureRow[] }) {
  const { messages } = useI18n()
  const percentText = usePercentText()

  return (
    <>
      <p className="exposure-note" role="note">
        {messages.dashboard.exposureNote}
      </p>
      <ul className="distribution" data-family="MULTI_VALUE_EXPOSURE">
        {rows.map((row) => (
          <li key={row.key} className="distribution__row" data-bucket={row.key}>
            <span className="distribution__label">{row.label}</span>
            <span className="distribution__count">
              {row.personCount} {messages.dashboard.persons}
            </span>
            <span className="distribution__percent" title={messages.dashboard.exposureShare}>
              {messages.dashboard.sharePrefix} {percentText(row.share)}
            </span>
            <span className="bar" aria-hidden="true">
              <span className="bar__fill" style={{ inlineSize: barWidth(row.share) }} />
            </span>
            {row.detail ? <span className="distribution__detail">{row.detail}</span> : null}
          </li>
        ))}
      </ul>
    </>
  )
}
