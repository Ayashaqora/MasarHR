import { useCallback, useState } from 'react'

/**
 * Tracks whether a `DateInput`/`MonthInput` field currently has something typed that its own `value` cannot
 * represent — an incomplete or invalid day/month/year (see those components' `onValidityChange` doc comment).
 *
 * A form's `validate()` must treat `hasUncommittedInput` as blocking review/submit, including for an optional
 * field: an empty `value` is otherwise ambiguous between "left blank on purpose" and "something invalid was
 * typed and silently dropped" — this is exactly the ambiguity `onValidityChange` exists to resolve, and a form
 * that ignores it can submit a payload that quietly omits a value the user actually tried to enter.
 */
export function useDateFieldValidity(): [boolean, (hasUncommittedInput: boolean) => void] {
  const [hasUncommittedInput, setHasUncommittedInput] = useState(false)
  const onValidityChange = useCallback((next: boolean) => setHasUncommittedInput(next), [])
  return [hasUncommittedInput, onValidityChange]
}
