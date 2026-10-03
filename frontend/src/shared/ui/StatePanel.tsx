import type { ReactNode } from 'react'
import { CircleAlert, CircleCheck, LoaderCircle } from 'lucide-react'
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert'
import { Skeleton } from '@/components/ui/skeleton'
import { cn } from '@/lib/utils'

type StatePanelTone = 'loading' | 'success' | 'error'

/**
 * Baseline loading / success / error surface (shadcn Alert).
 * Errors use role="alert"; loading and success are polite live regions.
 */
export function StatePanel({
  tone,
  title,
  children,
  action,
  className,
}: {
  tone: StatePanelTone
  title: string
  children?: ReactNode
  action?: ReactNode
  className?: string
}) {
  const Icon = tone === 'loading' ? LoaderCircle : tone === 'error' ? CircleAlert : CircleCheck

  return (
    <Alert
      role={tone === 'error' ? 'alert' : 'status'}
      aria-busy={tone === 'loading' ? true : undefined}
      variant={tone === 'error' ? 'destructive' : 'default'}
      data-tone={tone}
      className={cn(
        'my-4',
        tone === 'success' && 'border-status-success-border bg-status-success text-status-success-foreground *:data-[slot=alert-description]:text-status-success-foreground/90',
        className,
      )}
    >
      <Icon aria-hidden="true" className={cn(tone === 'loading' && 'animate-spin motion-reduce:animate-none')} />
      <AlertTitle className="line-clamp-none">{title}</AlertTitle>
      {children || tone === 'loading' || action ? (
        <AlertDescription>
          {children ? <div>{children}</div> : null}
          {tone === 'loading' ? (
            <div className="mt-2 space-y-2" aria-hidden="true">
              <Skeleton className="h-3 w-3/4" />
              <Skeleton className="h-3 w-1/2" />
            </div>
          ) : null}
          {action ? <div className="mt-3">{action}</div> : null}
        </AlertDescription>
      ) : null}
    </Alert>
  )
}
