import type { ReactNode } from 'react'
import { usePageTitle } from '../hooks/usePageTitle'

export function PageHeader({ title, description }: { title: string; description?: ReactNode }) {
  usePageTitle(title)

  return (
    <header className="page-header">
      <h1 className="page-header__title">{title}</h1>
      {description ? <p className="page-header__description">{description}</p> : null}
    </header>
  )
}
