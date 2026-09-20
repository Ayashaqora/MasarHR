import type { ReactNode } from 'react'

type StatePanelTone = 'loading' | 'success' | 'error'

/**
 * Baseline loading / success / error surface.
 * Errors use role="alert"; loading and success are polite live regions.
 */
export function StatePanel({
  tone,
  title,
  children,
  action,
}: {
  tone: StatePanelTone
  title: string
  children?: ReactNode
  action?: ReactNode
}) {
  return (
    <div
      className={`state-panel state-panel--${tone}`}
      role={tone === 'error' ? 'alert' : 'status'}
      aria-busy={tone === 'loading' ? true : undefined}
    >
      <p className="state-panel__title">{title}</p>
      {children ? <div className="state-panel__body">{children}</div> : null}
      {action ? <div className="state-panel__action">{action}</div> : null}
    </div>
  )
}
