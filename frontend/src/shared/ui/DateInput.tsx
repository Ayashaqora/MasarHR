import { useRef, useState, type KeyboardEvent } from 'react'
import { Calendar } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { useI18n } from '../../i18n/context'
import { isValidDateParts, isoToParts, partsToIso, sanitizeDigits } from '../lib/date'
import { FormField } from './FormField'

interface DateInputProps {
  label: string
  value: string
  onChange: (value: string) => void
  error: string | null
  required?: boolean
  helper?: string
  /**
   * Called whenever the typed day/month/year segments are non-empty but do not (yet, or ever) form a
   * complete, calendar-valid date — i.e. there is something typed that `value` cannot represent, because
   * `value`/`onChange` only ever carry a complete ISO date or an empty string. A form MUST treat `true` here
   * as blocking, even for an optional field: an empty `value` is otherwise ambiguous between "left blank on
   * purpose" and "something invalid was typed and silently dropped" — this callback resolves that ambiguity.
   */
  onValidityChange?: (hasUncommittedInput: boolean) => void
}

interface Segments {
  day: string
  month: string
  year: string
}

function segmentsFromIso(value: string): Segments {
  const parts = isoToParts(value)
  if (!parts) return { day: '', month: '', year: '' }
  return {
    day: String(parts.day).padStart(2, '0'),
    month: String(parts.month).padStart(2, '0'),
    year: String(parts.year).padStart(4, '0'),
  }
}

/** The committed ISO value (or null) for a set of typed segments, and whether anything typed can't be represented by it. */
function evaluateSegments(day: string, month: string, year: string): { iso: string | null; hasUncommittedInput: boolean } {
  const hasAnySegment = day !== '' || month !== '' || year !== ''
  const complete = day !== '' && month !== '' && year.length === 4
  const iso =
    complete && isValidDateParts(Number(year), Number(month), Number(day))
      ? partsToIso({ day: Number(day), month: Number(month), year: Number(year) })
      : null
  return { iso, hasUncommittedInput: hasAnySegment && iso === null }
}

/**
 * A day/month/year date input that displays and accepts dd/MM/yyyy (English digits, LTR direction),
 * independent of the browser's locale-dependent rendering of `<input type="date">`. It validates the
 * day/month/year combination precisely — including leap years — via `shared/lib/date`, and only ever calls
 * `onChange` with a complete, calendar-valid ISO date (`YYYY-MM-DD`) or an empty string: it never sends a
 * partial or invalid date. `onValidityChange` is how a form distinguishes "really empty" from "something
 * invalid was typed" — see its doc comment; a form must gate its review/submit step on it, including for
 * optional fields, so an invalid entry is never silently dropped.
 *
 * A visually-hidden (NOT `aria-hidden`) native `<input type="date">` carries the field's accessible label and
 * `id` (wired by `FormField`) and stays in sync with the three visible segments. It serves two purposes: the
 * calendar button opens the browser's native picker on it via `.showPicker()` (falling back to focusing the
 * day segment where `showPicker` is unavailable, e.g. in tests or unsupporting browsers), and it is exactly
 * what existing tests already interact with via `getByLabelText` / `fireEvent.change` / `toHaveValue` /
 * `toHaveAttribute('dir', 'ltr')` — so that interaction contract is unchanged. The visible segments carry
 * their own `aria-invalid`/`aria-describedby` (mirroring `FormField`'s convention for its field `id`), since a
 * screen-reader user interacts with them, not the hidden input.
 */
export function DateInput({ label, value, onChange, error, required = true, helper, onValidityChange }: DateInputProps) {
  const { messages } = useI18n()
  const d = messages.dateInput
  const nativeRef = useRef<HTMLInputElement | null>(null)
  const dayRef = useRef<HTMLInputElement | null>(null)
  const monthRef = useRef<HTMLInputElement | null>(null)
  const yearRef = useRef<HTMLInputElement | null>(null)

  const initial = segmentsFromIso(value)
  const [day, setDay] = useState(initial.day)
  const [month, setMonth] = useState(initial.month)
  const [year, setYear] = useState(initial.year)
  // The last ISO value *we* emitted via onChange, so an echo of our own change doesn't re-derive the segments
  // (which would fight the user's in-progress keystrokes). Plain state, not a ref: it must be read during
  // render below.
  const [lastEmitted, setLastEmitted] = useState(value)

  // Adjust the visible segments during render when `value` changes for a reason other than our own last
  // emission: an external reset by the parent form, or a direct change on the hidden native input (picker or
  // a test's fireEvent.change). This is React's documented pattern for deriving state from a changed prop,
  // rather than an effect, so there is no extra render before the UI reflects it.
  const [prevValue, setPrevValue] = useState(value)
  if (value !== prevValue) {
    setPrevValue(value)
    if (value !== lastEmitted) {
      setLastEmitted(value)
      const next = segmentsFromIso(value)
      setDay(next.day)
      setMonth(next.month)
      setYear(next.year)
      // `value` is always either '' or a complete valid ISO date, so segments derived from it are never in
      // the "something invalid typed" state.
      onValidityChange?.(false)
    }
  }

  const status = evaluateSegments(day, month, year)

  function commit(nextDay: string, nextMonth: string, nextYear: string) {
    const next = evaluateSegments(nextDay, nextMonth, nextYear)
    const nextValue = next.iso ?? ''
    if (lastEmitted !== nextValue) {
      setLastEmitted(nextValue)
      onChange(nextValue)
    }
    onValidityChange?.(next.hasUncommittedInput)
  }

  function handleDay(raw: string) {
    const digits = sanitizeDigits(raw).slice(0, 2)
    setDay(digits)
    commit(digits, month, year)
    if (digits.length === 2) monthRef.current?.focus()
  }

  function handleMonth(raw: string) {
    const digits = sanitizeDigits(raw).slice(0, 2)
    setMonth(digits)
    commit(day, digits, year)
    if (digits.length === 2) yearRef.current?.focus()
  }

  function handleYear(raw: string) {
    const digits = sanitizeDigits(raw).slice(0, 4)
    setYear(digits)
    commit(day, month, digits)
  }

  function handleBackspaceTo(previous: () => void) {
    return (event: KeyboardEvent<HTMLInputElement>) => {
      if (event.key !== 'Backspace') return
      if ((event.target as HTMLInputElement).value !== '') return
      event.preventDefault()
      previous()
    }
  }

  function handleNativeChange(nextIso: string) {
    setLastEmitted(nextIso)
    onChange(nextIso)
    const next = segmentsFromIso(nextIso)
    setDay(next.day)
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
      dayRef.current?.focus()
      return
    }
    try {
      native.showPicker()
    } catch {
      dayRef.current?.focus()
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
                ref={dayRef}
                inputMode="numeric"
                autoComplete="off"
                aria-label={`${label} — ${d.day}`}
                aria-describedby={segmentDescribedBy}
                aria-invalid={segmentInvalid}
                placeholder={d.dayPlaceholder}
                maxLength={2}
                className="w-14 text-center"
                value={day}
                onChange={(event) => handleDay(event.target.value)}
              />
              <span aria-hidden="true" className="text-muted-foreground">
                /
              </span>
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
                onKeyDown={handleBackspaceTo(() => dayRef.current?.focus())}
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
                onKeyDown={handleBackspaceTo(() => monthRef.current?.focus())}
              />
              <Button type="button" variant="outline" size="icon-sm" aria-label={d.openCalendar} onClick={openPicker}>
                <Calendar aria-hidden="true" />
              </Button>
              <input
                {...props}
                ref={nativeRef}
                type="date"
                dir="ltr"
                tabIndex={-1}
                className="sr-only"
                value={value}
                onChange={(event) => handleNativeChange(event.target.value)}
              />
            </div>
            {status.hasUncommittedInput ? <p className="text-xs text-destructive">{d.invalidDate}</p> : null}
          </div>
        )
      }}
    </FormField>
  )
}
