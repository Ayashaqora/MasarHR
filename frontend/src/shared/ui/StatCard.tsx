import type { ReactNode } from 'react'
import { Card, CardContent } from '@/components/ui/card'

/** One headline figure. `valueTestId` keeps the value addressable for tests without styling hooks. */
export function StatCard({
  label,
  value,
  hint,
  valueTestId,
  icon,
  ...rest
}: {
  label: string
  value: ReactNode
  hint?: string
  valueTestId?: string
  icon?: ReactNode
} & Record<`data-${string}`, string | undefined>) {
  return (
    <Card className="gap-2 py-4" {...rest}>
      <CardContent className="space-y-1 px-5">
        <div className="flex items-center justify-between gap-3">
          <p className="text-sm font-medium text-muted-foreground">{label}</p>
          {icon ? <span aria-hidden="true" className="text-muted-foreground">{icon}</span> : null}
        </div>
        <p className="text-3xl leading-tight font-semibold tabular-nums" data-testid={valueTestId}>
          {value}
        </p>
        {hint ? <p className="text-xs text-muted-foreground">{hint}</p> : null}
      </CardContent>
    </Card>
  )
}
