/** A reporting month is always the explicit first day of a month (YYYY-MM-01), exactly what the backend validates. */
export const REPORTING_MONTH_PATTERN = /^\d{4}-(0[1-9]|1[0-2])-01$/

export function isReportingMonth(value: string): boolean {
  return REPORTING_MONTH_PATTERN.test(value)
}

/** The first day of the month containing `now` (local calendar). */
export function defaultReportingMonth(now: Date = new Date()): string {
  const month = String(now.getMonth() + 1).padStart(2, '0')
  return `${now.getFullYear()}-${month}-01`
}

/** An <input type="month"> value (YYYY-MM) to the API month (YYYY-MM-01); null when it is not a valid month. */
export function monthInputToReportingMonth(value: string): string | null {
  const candidate = /^\d{4}-\d{2}$/.test(value) ? `${value}-01` : ''
  return isReportingMonth(candidate) ? candidate : null
}

/** The API month back to the <input type="month"> value. */
export function reportingMonthToMonthInput(month: string): string {
  return month.slice(0, 7)
}
