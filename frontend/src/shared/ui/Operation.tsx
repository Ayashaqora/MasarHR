import { useContext, useEffect, useId, useRef, useState, type FormEvent, type ReactNode } from 'react'
import { CircleCheck, Loader2, TriangleAlert } from 'lucide-react'
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert'
import { Button } from '@/components/ui/button'
import { Sheet, SheetContent, SheetDescription, SheetHeader, SheetTitle } from '@/components/ui/sheet'
import { useI18n } from '../../i18n/context'
import type { MutationFailure } from '../api/mutationError'
import { FeedbackContext } from './feedbackContext'
import { SectionCard } from './SectionCard'

/**
 * Building blocks for Employee 360 write operations (S46). A bar holds the action buttons of one area; each
 * action opens a Sheet containing a form. The bar owns the success announcement so it survives the data
 * refetch that follows a successful write (the fields' sheet is closed by then).
 */

/** The page-level success announcement: it lives above any data-dependent branch so a refetch cannot erase it. */
export function OperationFeedback({ message, onDismiss }: { message: string | null; onDismiss: () => void }) {
  const { messages } = useI18n()
  if (!message) {
    return null
  }
  return (
    <Alert role="status" className="mb-4 border-status-success-border bg-status-success text-status-success-foreground">
      <CircleCheck aria-hidden="true" />
      <AlertDescription className="flex flex-wrap items-center justify-between gap-2 text-status-success-foreground">
        <span>{message}</span>
        <Button type="button" variant="ghost" size="sm" onClick={onDismiss}>
          {messages.operations.dismiss}
        </Button>
      </AlertDescription>
    </Alert>
  )
}

export function OperationBar({
  headingId,
  title,
  description,
  note,
  children,
}: {
  headingId: string
  title: string
  description?: string
  note?: ReactNode
  children?: ReactNode
}) {
  return (
    <SectionCard level={3} headingId={headingId} title={title} description={description}>
      {note ? <p className="text-sm text-muted-foreground">{note}</p> : null}
      {children ? <div className="flex flex-wrap items-start gap-2">{children}</div> : null}
    </SectionCard>
  )
}

/** Renders the trigger button and, when open, the sheet; `children` receives `done(successMessage)`. */
export function OperationAction({
  label,
  icon,
  variant = 'outline',
  title,
  description,
  disabled,
  disabledReason,
  children,
}: {
  label: string
  icon?: ReactNode
  variant?: 'default' | 'outline' | 'destructive'
  title: string
  description: string
  disabled?: boolean
  disabledReason?: string
  children: (api: { done: (successMessage: string) => void; cancel: () => void }) => ReactNode
}) {
  const { messages } = useI18n()
  const [open, setOpen] = useState(false)
  const notify = useContext(FeedbackContext)
  const reasonId = useId()

  return (
    <>
      <span className="inline-flex flex-col items-start gap-1">
        <Button
          type="button"
          variant={variant}
          size="sm"
          disabled={disabled}
          aria-describedby={disabled && disabledReason ? reasonId : undefined}
          onClick={() => setOpen(true)}
        >
          {icon}
          {label}
        </Button>
        {disabled && disabledReason ? (
          <span id={reasonId} className="text-xs text-muted-foreground">
            {disabledReason}
          </span>
        ) : null}
      </span>
      <Sheet open={open} onOpenChange={setOpen}>
        <SheetContent className="w-full overflow-y-auto sm:max-w-md" closeLabel={messages.app.close}>
          <SheetHeader>
            <SheetTitle>{title}</SheetTitle>
            <SheetDescription>{description}</SheetDescription>
          </SheetHeader>
          {open
            ? children({
                done: (successMessage) => {
                  setOpen(false)
                  notify(successMessage)
                },
                cancel: () => setOpen(false),
              })
            : null}
        </SheetContent>
      </Sheet>
    </>
  )
}

/**
 * The form frame: client-side required-field validation (`validate`, deterministic and locale-aware),
 * an optional review step for high-impact operations, the failure banner, and pending-safe buttons.
 * A 422 returns the user to the editable fields so the server's field messages are visible.
 */
export function OperationForm({
  pending,
  failure,
  submitLabel,
  destructive,
  review,
  validate,
  onSubmit,
  onCancel,
  onRefresh,
  children,
}: {
  pending: boolean
  failure: MutationFailure | null
  submitLabel: string
  destructive?: boolean
  review?: ReactNode
  validate: () => boolean
  onSubmit: () => void | Promise<void>
  onCancel: () => void
  /** Offered on a 409 so the user can reload the canonical data instead of retrying blindly. */
  onRefresh?: () => void
  children: ReactNode
}) {
  const { messages } = useI18n()
  const [phase, setPhase] = useState<'edit' | 'review'>('edit')
  const bannerRef = useRef<HTMLDivElement>(null)

  const inReview = phase === 'review' && review !== undefined && failure?.kind !== 'validation'

  useEffect(() => {
    if (failure) {
      bannerRef.current?.focus()
    }
  }, [failure])

  function handleSubmit(event: FormEvent) {
    event.preventDefault()
    if (pending) {
      return
    }
    if (!validate()) {
      setPhase('edit')
      return
    }
    if (review !== undefined && !inReview) {
      setPhase('review')
      return
    }
    void onSubmit()
  }

  const finalLabel = review !== undefined && !inReview ? messages.operations.review : submitLabel

  return (
    <form noValidate onSubmit={handleSubmit} aria-busy={pending} className="flex flex-1 flex-col gap-4 px-4 pb-4">
      {failure ? (
        <Alert variant="destructive" role="alert" tabIndex={-1} ref={bannerRef}>
          <TriangleAlert aria-hidden="true" />
          <AlertTitle>{failure.message}</AlertTitle>
          {failure.detail ? <AlertDescription>{failure.detail}</AlertDescription> : null}
          {failure.kind === 'conflict' && onRefresh ? (
            <Button type="button" variant="outline" size="sm" className="mt-2" onClick={onRefresh}>
              {messages.operations.refresh}
            </Button>
          ) : null}
        </Alert>
      ) : null}

      {inReview ? (
        <div className="space-y-3">
          <p className="text-sm font-medium">{messages.operations.reviewTitle}</p>
          {review}
        </div>
      ) : (
        <div className="space-y-4">{children}</div>
      )}

      <div className="mt-auto flex flex-wrap justify-end gap-2 pt-2">
        {inReview ? (
          <Button type="button" variant="ghost" disabled={pending} onClick={() => setPhase('edit')}>
            {messages.operations.back}
          </Button>
        ) : null}
        <Button type="button" variant="outline" disabled={pending} onClick={onCancel}>
          {messages.operations.cancel}
        </Button>
        <Button type="submit" variant={destructive && (review === undefined || inReview) ? 'destructive' : 'default'} disabled={pending}>
          {pending ? <Loader2 aria-hidden="true" className="animate-spin" /> : null}
          {pending ? messages.operations.saving : finalLabel}
        </Button>
      </div>
    </form>
  )
}
