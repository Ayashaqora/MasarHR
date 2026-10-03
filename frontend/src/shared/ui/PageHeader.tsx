import type { ReactNode } from 'react'
import { usePageTitle } from '../hooks/usePageTitle'

export function PageHeader({ title, description, actions }: { title: string; description?: ReactNode; actions?: ReactNode }) {
  usePageTitle(title)

  return (
    <header className="mb-6 flex flex-wrap items-start justify-between gap-4">
      <div className="min-w-0 space-y-1">
        <h1 className="text-2xl leading-tight font-semibold tracking-normal">{title}</h1>
        {description ? <p className="max-w-3xl text-sm text-muted-foreground">{description}</p> : null}
      </div>
      {actions ? <div className="flex shrink-0 items-center gap-2">{actions}</div> : null}
    </header>
  )
}
