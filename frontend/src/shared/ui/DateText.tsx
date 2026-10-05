import { formatDisplayDate } from '../lib/date'
import { Ltr } from './Ltr'

/**
 * A recorded date. Displays as dd/MM/yyyy (English digits, kept left-to-right so the digits never reorder in
 * Arabic text) regardless of locale — only the API/database value stays ISO. If `value` is set but isn't a
 * valid ISO date, it is shown as-is rather than silently hidden, so unexpected data stays visible.
 */
export function DateText({ value, fallback = '—' }: { value: string | null | undefined; fallback?: string }) {
  if (!value) return <>{fallback}</>
  return <Ltr>{formatDisplayDate(value, fallback)}</Ltr>
}
