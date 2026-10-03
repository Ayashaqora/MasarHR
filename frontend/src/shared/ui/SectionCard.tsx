import type { ComponentProps, ReactNode } from 'react'
import { Card, CardAction, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { cn } from '@/lib/utils'

/**
 * The standard titled surface: a Card whose heading labels the region (`aria-labelledby`).
 * `level` is the heading level (2 for page sections, 3 for sub-sections).
 */
export function SectionCard({
  headingId,
  title,
  description,
  action,
  level = 2,
  children,
  className,
  contentClassName,
  ...rest
}: {
  headingId: string
  title: ReactNode
  description?: ReactNode
  action?: ReactNode
  level?: 2 | 3
  children: ReactNode
  contentClassName?: string
} & Omit<ComponentProps<'section'>, 'title' | 'aria-labelledby'>) {
  const Heading = level === 2 ? 'h2' : 'h3'

  return (
    <section aria-labelledby={headingId} {...rest}>
      <Card className={cn('gap-4 py-5', className)}>
        <CardHeader className="px-5">
          <CardTitle>
            <Heading id={headingId} className="flex flex-wrap items-center gap-2 text-base leading-snug font-semibold">
              {title}
            </Heading>
          </CardTitle>
          {description ? <CardDescription>{description}</CardDescription> : null}
          {action ? <CardAction>{action}</CardAction> : null}
        </CardHeader>
        <CardContent className={cn('space-y-4 px-5', contentClassName)}>{children}</CardContent>
      </Card>
    </section>
  )
}
