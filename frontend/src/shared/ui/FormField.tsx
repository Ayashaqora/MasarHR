import { useId, type ReactNode } from 'react'
import { Label } from '@/components/ui/label'

/**
 * Visible label + control + helper text + inline error, wired with aria-describedby / aria-invalid by the
 * caller through the render prop. Errors announce themselves (role="alert").
 */
export function FormField({
  label,
  required,
  helper,
  error,
  children,
}: {
  label: string
  required?: boolean
  helper?: string
  error?: string | null
  children: (props: { id: string; 'aria-describedby'?: string; 'aria-invalid'?: true }) => ReactNode
}) {
  const id = useId()
  const helperId = `${id}-helper`
  const errorId = `${id}-error`
  const describedBy = [helper ? helperId : null, error ? errorId : null].filter(Boolean).join(' ') || undefined

  return (
    <div className="space-y-2">
      <div className="flex items-center gap-1">
        <Label htmlFor={id}>{label}</Label>
        {required ? (
          <span aria-hidden="true" className="text-sm leading-none text-destructive">
            *
          </span>
        ) : null}
      </div>
      {children({ id, 'aria-describedby': describedBy, 'aria-invalid': error ? true : undefined })}
      {helper ? (
        <p id={helperId} className="text-xs text-muted-foreground">
          {helper}
        </p>
      ) : null}
      {error ? (
        <p id={errorId} role="alert" className="text-sm font-medium text-destructive">
          {error}
        </p>
      ) : null}
    </div>
  )
}
