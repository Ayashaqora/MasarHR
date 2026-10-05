/**
 * Pure date helpers for UI-DATE-001.
 *
 * Scope: these functions only affect how dates are DISPLAYED and TYPED in the UI
 * (dd/MM/yyyy for days, MM/yyyy for months). They never change what is sent to the
 * API or stored: the canonical value everywhere else in the app stays ISO
 * (YYYY-MM-DD for a day, YYYY-MM for a month-input value). Nothing here depends on
 * the browser's locale-dependent rendering of `<input type="date">`.
 */

const ISO_DATE_PATTERN = /^(\d{4})-(\d{2})-(\d{2})$/
const ISO_MONTH_PATTERN = /^(\d{4})-(\d{2})$/

export interface DateParts {
  day: number
  month: number
  year: number
}

/** Gregorian leap-year rule: divisible by 4, except centuries, unless divisible by 400. */
export function isLeapYear(year: number): boolean {
  return (year % 4 === 0 && year % 100 !== 0) || year % 400 === 0
}

/** Number of days in `month` (1-12) of `year`, accounting for leap years. */
export function daysInMonth(year: number, month: number): number {
  const lengths = [31, isLeapYear(year) ? 29 : 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31]
  return lengths[month - 1] ?? 0
}

/** The year range accepted everywhere a year is typed in the UI (day-dates and month-only inputs alike): a 4-digit calendar year, never 0000. */
export function isValidYear(year: number): boolean {
  return Number.isInteger(year) && year >= 1 && year <= 9999
}

/** True when day/month/year form a real Gregorian calendar date. */
export function isValidDateParts(year: number, month: number, day: number): boolean {
  if (!isValidYear(year) || !Number.isInteger(month) || !Number.isInteger(day)) return false
  if (month < 1 || month > 12) return false
  if (day < 1 || day > daysInMonth(year, month)) return false
  return true
}

/** True when year/month form a real calendar month — the same year rule as `isValidDateParts`, for month-only inputs (e.g. `MonthInput`) that have no day segment. */
export function isValidMonthParts(year: number, month: number): boolean {
  if (!isValidYear(year) || !Number.isInteger(month)) return false
  if (month < 1 || month > 12) return false
  return true
}

/** Convert Arabic-Indic digits (٠-٩) to Western digits and strip everything else that isn't a digit. */
export function sanitizeDigits(input: string): string {
  const arabicIndic = '٠١٢٣٤٥٦٧٨٩'
  let out = ''
  for (const ch of input) {
    const idx = arabicIndic.indexOf(ch)
    if (idx >= 0) {
      out += String(idx)
    } else if (ch >= '0' && ch <= '9') {
      out += ch
    }
  }
  return out
}

/** Parse a canonical ISO date string (YYYY-MM-DD) into its numeric parts, or null if malformed/invalid. */
export function isoToParts(iso: string | null | undefined): DateParts | null {
  if (!iso) return null
  const match = ISO_DATE_PATTERN.exec(iso)
  if (!match) return null
  const year = Number(match[1])
  const month = Number(match[2])
  const day = Number(match[3])
  if (!isValidDateParts(year, month, day)) return null
  return { day, month, year }
}

/** Build a canonical ISO date string (YYYY-MM-DD) from parts, or null when the parts don't form a valid calendar date. */
export function partsToIso(parts: Partial<DateParts>): string | null {
  const { day, month, year } = parts
  if (day == null || month == null || year == null) return null
  if (!isValidDateParts(year, month, day)) return null
  const yyyy = String(year).padStart(4, '0')
  const mm = String(month).padStart(2, '0')
  const dd = String(day).padStart(2, '0')
  return `${yyyy}-${mm}-${dd}`
}

/** Format an ISO date (YYYY-MM-DD) as dd/MM/yyyy for display. Returns null if `iso` isn't a valid ISO date (never silently mangles unexpected data). */
export function formatIsoAsDisplayDate(iso: string | null | undefined): string | null {
  const parts = isoToParts(iso)
  if (!parts) return null
  const dd = String(parts.day).padStart(2, '0')
  const mm = String(parts.month).padStart(2, '0')
  const yyyy = String(parts.year).padStart(4, '0')
  return `${dd}/${mm}/${yyyy}`
}

/**
 * Format an ISO date for display with an explicit fallback for a missing value — the plain-string counterpart
 * of `DateText`, for places (e.g. `Timeline` entries) that need a string rather than JSX. If `value` is set but
 * isn't a valid ISO date, it is returned as-is rather than silently hidden.
 */
export function formatDisplayDate(value: string | null | undefined, fallback = '—'): string {
  if (!value) return fallback
  return formatIsoAsDisplayDate(value) ?? value
}

/**
 * Format a full timestamp's DATE portion only, from a JS `Date`, as dd/MM/yyyy (English digits) in the
 * runtime's local time zone. For a timestamp display (e.g. the system-status "checked at" time) whose time
 * portion is already correctly rendered elsewhere (e.g. via `Intl.DateTimeFormat` with `timeStyle` only) and
 * must be preserved unchanged, together with its time zone — only the date part is reformatted.
 */
export function formatDateOnly(date: Date): string {
  const dd = String(date.getDate()).padStart(2, '0')
  const mm = String(date.getMonth() + 1).padStart(2, '0')
  const yyyy = String(date.getFullYear()).padStart(4, '0')
  return `${dd}/${mm}/${yyyy}`
}

/** Format an ISO month value (YYYY-MM, as used by the dashboard's month input) as MM/yyyy for display. Returns null if malformed. */
export function formatIsoMonthAsDisplay(yyyyMM: string | null | undefined): string | null {
  if (!yyyyMM) return null
  const match = ISO_MONTH_PATTERN.exec(yyyyMM)
  if (!match) return null
  const year = match[1]!
  const month = match[2]!
  if (Number(month) < 1 || Number(month) > 12) return null
  return `${month}/${year}`
}
