import type { ReactNode } from 'react'
import { Ltr } from './Ltr'

export interface TimelineEntry {
  key: string
  /** The headline: usually a StatusBadge or a movement type. */
  title: ReactNode
  /** Free text under the headline (unit name, notes). */
  detail?: ReactNode
  from: string
  /** Already-localized label for the end of the period (a date, or "open-ended"). */
  to: string
}

/**
 * A chronological list (newest first, as given) of periods. It states each period's own start and end exactly as
 * recorded; it never labels a row "current" — current state is read from the backend's effective-state endpoints elsewhere.
 */
export function Timeline({ entries, label, fromLabel, toLabel }: { entries: readonly TimelineEntry[]; label: string; fromLabel: string; toLabel: string }) {
  return (
    <ol aria-label={label} className="relative space-y-4 border-s ps-6">
      {entries.map((entry) => (
        <li key={entry.key} className="relative">
          <span aria-hidden="true" className="absolute -start-[1.9rem] top-2 size-3 rounded-full border-2 border-primary bg-card" />
          <div className="flex flex-wrap items-center gap-2">{entry.title}</div>
          {entry.detail ? <p className="mt-1 text-sm text-foreground">{entry.detail}</p> : null}
          <p className="mt-1 text-xs text-muted-foreground">
            {fromLabel} <Ltr>{entry.from}</Ltr> · {toLabel} <Ltr>{entry.to}</Ltr>
          </p>
        </li>
      ))}
    </ol>
  )
}
