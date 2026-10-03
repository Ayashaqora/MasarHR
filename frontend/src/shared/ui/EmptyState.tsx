import type { ReactNode } from 'react'
import { Inbox } from 'lucide-react'

/** A neutral "nothing recorded" surface — used where the absence of rows is itself the answer. */
export function EmptyState({ title, children }: { title: string; children?: ReactNode }) {
  return (
    <div
      role="status"
      className="flex flex-col items-center gap-2 rounded-lg border border-dashed bg-muted/40 px-4 py-8 text-center"
    >
      <Inbox aria-hidden="true" className="size-6 text-muted-foreground" />
      <p className="text-sm font-medium">{title}</p>
      {children ? <p className="max-w-prose text-sm text-muted-foreground">{children}</p> : null}
    </div>
  )
}
