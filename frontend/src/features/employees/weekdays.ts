import type { Messages } from '../../i18n/messages/types'

const WEEKDAY_KEYS = {
  SUNDAY: 'weekdaySunday',
  MONDAY: 'weekdayMonday',
  TUESDAY: 'weekdayTuesday',
  WEDNESDAY: 'weekdayWednesday',
  THURSDAY: 'weekdayThursday',
  FRIDAY: 'weekdayFriday',
  SATURDAY: 'weekdaySaturday',
} as const

/** Display-only label for a ref.weekdays code; an unknown code is shown as-is, never guessed. */
export function weekdayLabel(code: string, messages: Messages): string {
  const key = WEEKDAY_KEYS[code as keyof typeof WEEKDAY_KEYS]
  return key ? messages.employee360[key] : code
}

export function weekdaysLabel(codes: readonly string[], messages: Messages): string {
  return codes.map((code) => weekdayLabel(code, messages)).join('، ')
}
