import type { ReactNode } from 'react'
import { cn } from '@/lib/utils'

/** Label/value pairs: stacked on mobile, two columns from `sm` upward. */
export function DefinitionList({ children, className }: { children: ReactNode; className?: string }) {
  return <dl className={cn('grid gap-x-6 gap-y-4 sm:grid-cols-2', className)}>{children}</dl>
}

export function DefinitionItem({ label, children, className }: { label: ReactNode; children: ReactNode; className?: string }) {
  return (
    <div className={cn('min-w-0 space-y-1 border-b pb-3 last:border-b-0 sm:[&:nth-last-child(2):nth-child(odd)]:border-b-0', className)}>
      <dt className="text-xs font-medium text-muted-foreground">{label}</dt>
      <dd className="text-sm font-medium break-words text-foreground">{children}</dd>
    </div>
  )
}
