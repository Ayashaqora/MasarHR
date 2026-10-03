import { Ltr } from './Ltr'

/** A recorded date (kept left-to-right so the digits never reorder in Arabic text) or its explicit fallback label. */
export function DateText({ value, fallback = '—' }: { value: string | null | undefined; fallback?: string }) {
  return value ? <Ltr>{value}</Ltr> : <>{fallback}</>
}
