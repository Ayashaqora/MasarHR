import { useId } from 'react'
import { useI18n } from '../../../i18n/context'
import { WEEKDAY_CODES, weekdayLabel } from '../weekdays'

export function WeekdayPicker({
  legend,
  helper,
  value,
  onChange,
  error,
}: {
  legend: string
  helper?: string
  value: readonly string[]
  onChange: (next: string[]) => void
  error: string | null
}) {
  const { messages } = useI18n()
  const id = useId()
  const describedBy = [helper ? `${id}-helper` : null, error ? `${id}-error` : null].filter(Boolean).join(' ') || undefined

  return (
    <fieldset className="space-y-2" aria-describedby={describedBy} aria-invalid={error ? true : undefined}>
      <legend className="text-sm leading-none font-medium">
        {legend} <span aria-hidden="true" className="text-destructive">*</span>
      </legend>
      <div className="grid grid-cols-2 gap-2">
        {WEEKDAY_CODES.map((code) => {
          const inputId = `${id}-${code}`
          return (
            <label key={code} htmlFor={inputId} className="flex min-h-9 items-center gap-2 rounded-md border border-input px-3 py-1 text-sm">
              <input
                id={inputId}
                type="checkbox"
                className="size-4 accent-primary"
                checked={value.includes(code)}
                onChange={(event) =>
                  onChange(event.target.checked ? [...value, code] : value.filter((item) => item !== code))
                }
              />
              {weekdayLabel(code, messages)}
            </label>
          )
        })}
      </div>
      {helper ? (
        <p id={`${id}-helper`} className="text-xs text-muted-foreground">
          {helper}
        </p>
      ) : null}
      {error ? (
        <p id={`${id}-error`} role="alert" className="text-sm font-medium text-destructive">
          {error}
        </p>
      ) : null}
    </fieldset>
  )
}
