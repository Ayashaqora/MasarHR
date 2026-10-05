import { useRef, useState, type KeyboardEvent } from 'react'
import { Calendar } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { useI18n } from '../../i18n/context'
import { isValidMonthParts, sanitizeDigits } from '../lib/date'
import { FormField } from './FormField'

interface MonthInputProps {
  label: string
  value: string
  onChange: (value: string) => void
  error: string | null
  required?: boolean
  helper?: string
  /**
   * Called whenever the typed month/year segments are non-empty but do not (yet, or ever) form a valid
   * "YYYY-MM" month — i.e. there is something typed that `value` cannot represent, because `value`/`onChange`
   * only ever carry a complete valid month or an empty string. A form MUST treat `true` here as blocking, even
   * for an optional field: an empty `value` is otherwise ambiguous between "left blank on purpose" and
   * "something invalid was typed and silently dropped" — this callback resolves that ambiguity. See the
   * matching contract on `DateInput`.
   */
  onValidityChange?: (hasUncommittedInput: boolean) => void
}

interface MonthSegments {
  month: string
  year: string
}

const MONTH_PATTERN = /^(\d{4})-(\d{2})$/

function segmentsFromMonth(value: string): MonthSegments {
  const match = MONTH_PATTERN.exec(value)
  return match ? { month: match[2]!, year: match[1]! } : { month: '', year: '' }
}

/**
 * The committed "YYYY-MM" value (or null) for a set of typed segments, and whether anything typed can't be
 * represented by it. Validity uses `isValidMonthParts` — the same year range (1-9999, never 0000) already
 * approved for `DateInput`'s day-dates — rather than a looser ad-hoc digit-length check, so a nonsense year
 * like "0000" is never silently committed as if it were a real reporting month.
 */
function evaluateSegments(month: string, year: string): { iso: string | null; hasUncommittedInput: boolean } {
  const hasAnySegment = month !== '' || year !== ''
  const complete = month !== '' && year.length === 4
  const iso = complete && isValidMonthParts(Number(year), Number(month)) ? `${year.padStart(4, '0')}-${month.padStart(2, '0')}` : null
  return { iso, hasUncommittedInput: hasAnySegment && iso === null }
}

/**
 * A month/year input that displays and accepts MM/yyyy (English digits, LTR direction), mirroring the
 * external value("YYYY-MM")/onChange(nextYYYYMM) contract of the native `<input type="month">` it replaces —
 * so callers keep their existing validation (`monthInputToReportingMonth` in `features/dashboard/month.ts`)
 * completely unchanged. It only ever emits a fully-typed, calendar-valid "YYYY-MM" or an empty string, never a
 * partial or nonsense value (e.g. year "0000"). `onValidityChange` is how a form distinguishes "really empty"
 * from "something invalid was typed" — see its doc comment; a form must gate its review/submit step on it,
 * including for optional fields, so an invalid entry is never silently dropped.
 *
 * As with `DateInput`, a visually-hidden (not `aria-hidden`) native `<input type="month">` carries the
 * field's accessible label/id and is the target of the calendar button's `.showPicker()` (falling back to
 * focusing the month segment where `showPicker` is unavailable, e.g. in tests or unsupporting browsers). The
 * visible segments carry their own `aria-invalid`/`aria-describedby` (mirroring `FormField`'s convention for
 * its field `id`), since a screen-reader user interacts with them, not the hidden input.
 */
export function MonthInput({ label, value, onChange, error, required, helper, onValidityChange }: MonthInputProps) {
  const { messages } = useI18n()
  const d = messages.dateInput
  const nativeRef = useRef<HTMLInputElement | null>(null)
  const monthRef = useRef<HTMLInputElement | null>(null)
  const yearRef = useRef<HTMLInputElement | null>(null)
  const initial = segmentsFromMonth(value)
  const [month, setMonth] = useState(initial.month)
  const [year, setYear] = useState(initial.year)
  // The last "YYYY-MM" *we* emitted via onChange, so an echo of our own change doesn't re-derive the segments.
  // Plain state, not a ref: it must be read during render below.
  const [lastEmitted, setLastEmitted] = useState(value)

  // Adjust state during render when `value` changes for a reason other than our own last emission (React's
  // documented pattern for deriving state from a changed prop), rather than in an effect.
  const [prevValue, setPrevValue] = useState(value)
  if (value !== prevValue) {
    setPrevValue(value)
    if (value !== lastEmitted) {
      setLastEmitted(value)
      const next = segmentsFromMonth(value)
      setMonth(next.month)
      setYear(next.year)
      // `value` is always either '' or a complete valid month, so segments derived from it are never in the
      // "something invalid typed" state.
      onValidityChange?.(false)
    }
  }

  const status = evaluateSegments(month, year)

  function commit(nextMonth: string, nextYear: string) {
    const next = evaluateSegments(nextMonth, nextYear)
    const nextValue = next.iso ?? ''
    if (lastEmitted !== nextValue) {
      setLastEmitted(nextValue)
      onChange(nextValue)
    }
    onValidityChange?.(next.hasUncommittedInput)
  }

  function handleMonth(raw: string) {
    const digits = sanitizeDigits(raw).slice(0, 2)
    setMonth(digits)
    commit(digits, year)
    if (digits.length === 2) yearRef.current?.focus()
  }

  function handleYear(raw: string) {
    const digits = sanitizeDigits(raw).slice(0, 4)
    setYear(digits)
    commit(month, digits)
  }

  function handleBackspaceToMonth(event: KeyboardEvent<HTMLInputElement>) {
    if (event.key !== 'Backspace') return
    if ((event.target as HTMLInputElement).value !== '') return
    event.preventDefault()
    monthRef.current?.focus()
  }

  function handleNativeChange(nextValue: string) {
    setLastEmitted(nextValue)
    onChange(nextValue)
    const next = segmentsFromMonth(nextValue)
    setMonth(next.month)
    setYear(next.year)
    onValidityChange?.(false)
  }

  function openPicker() {
    const native = nativeRef.current
    // `showPicker` can be missing entirely (older browsers, jsdom in most configurations) or present but throw
    // when called (jsdom's own stub implementation, or a real browser refusing an untrusted call) — both are
    // "unavailable" here, and both fall back to a focus the user can act on with the keyboard, rather than a
    // dead button.
    if (!native || typeof native.showPicker !== 'function') {
      monthRef.current?.focus()
      return
    }
    try {
      native.showPicker()
    } catch {
      monthRef.current?.focus()
    }
  }

  return (
    <FormField label={label} required={required} helper={helper} error={error}>
      {(props) => {
        const helperId = `${props.id}-helper`
        const errorId = `${props.id}-error`
        const segmentDescribedBy = [helper ? helperId : null, error ? errorId : null].filter(Boolean).join(' ') || undefined
        const segmentInvalid = error || status.hasUncommittedInput ? true : undefined
        return (
          <div className="space-y-1">
            <div dir="ltr" className="flex items-center gap-1">
              <Input
                ref={monthRef}
                inputMode="numeric"
                autoComplete="off"
                aria-label={`${label} — ${d.month}`}
                aria-describedby={segmentDescribedBy}
                aria-invalid={segmentInvalid}
                placeholder={d.monthPlaceholder}
                maxLength={2}
                className="w-14 text-center"
                value={month}
                onChange={(event) => handleMonth(event.target.value)}
              />
              <span aria-hidden="true" className="text-muted-foreground">
                /
              </span>
              <Input
                ref={yearRef}
                inputMode="numeric"
                autoComplete="off"
                aria-label={`${label} — ${d.year}`}
                aria-describedby={segmentDescribedBy}
                aria-invalid={segmentInvalid}
                placeholder={d.yearPlaceholder}
                maxLength={4}
                className="w-20 text-center"
                value={year}
                onChange={(event) => handleYear(event.target.value)}
                onKeyDown={handleBackspaceToMonth}
              />
              <Button type="button" variant="outline" size="icon-sm" aria-label={d.openCalendar} onClick={openPicker}>
                <Calendar aria-hidden="true" />
              </Button>
              <input
                {...props}
                ref={nativeRef}
                type="month"
                dir="ltr"
                tabIndex={-1}
                className="sr-only"
                value={value}
                onChange={(event) => handleNativeChange(event.target.value)}
              />
            </div>
            {status.hasUncommittedInput ? <p className="text-xs text-destructive">{d.invalidMonth}</p> : null}
          </div>
        )
      }}
    </FormField>
  )
}
