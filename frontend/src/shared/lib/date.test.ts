import { describe, expect, it } from 'vitest'
import {
  daysInMonth,
  formatDateOnly,
  formatDisplayDate,
  formatIsoAsDisplayDate,
  formatIsoMonthAsDisplay,
  isLeapYear,
  isValidDateParts,
  isValidMonthParts,
  isValidYear,
  isoToParts,
  partsToIso,
  sanitizeDigits,
} from './date'

describe('isLeapYear', () => {
  it('treats years divisible by 4 (but not by 100) as leap years', () => {
    expect(isLeapYear(2024)).toBe(true)
    expect(isLeapYear(2023)).toBe(false)
  })

  it('treats century years divisible by 100 but not 400 as non-leap', () => {
    expect(isLeapYear(1900)).toBe(false)
  })

  it('treats century years divisible by 400 as leap', () => {
    expect(isLeapYear(2000)).toBe(true)
  })
})

describe('daysInMonth', () => {
  it('returns 29 for February in a leap year', () => {
    expect(daysInMonth(2024, 2)).toBe(29)
  })

  it('returns 28 for February in a non-leap year', () => {
    expect(daysInMonth(2023, 2)).toBe(28)
  })

  it('returns 31/30 correctly for other months', () => {
    expect(daysInMonth(2026, 1)).toBe(31)
    expect(daysInMonth(2026, 4)).toBe(30)
    expect(daysInMonth(2026, 12)).toBe(31)
  })
})

describe('isValidDateParts', () => {
  it('accepts a real calendar date', () => {
    expect(isValidDateParts(2026, 10, 5)).toBe(true)
  })

  it('rejects an out-of-range day for the given month', () => {
    expect(isValidDateParts(2026, 4, 31)).toBe(false)
  })

  it('rejects Feb 29 on a non-leap year', () => {
    expect(isValidDateParts(2023, 2, 29)).toBe(false)
  })

  it('accepts Feb 29 on a leap year', () => {
    expect(isValidDateParts(2024, 2, 29)).toBe(true)
  })

  it('rejects an invalid month', () => {
    expect(isValidDateParts(2026, 13, 1)).toBe(false)
    expect(isValidDateParts(2026, 0, 1)).toBe(false)
  })

  it('rejects an invalid day', () => {
    expect(isValidDateParts(2026, 1, 0)).toBe(false)
    expect(isValidDateParts(2026, 1, 32)).toBe(false)
  })

  it('rejects the year 0000 (S46 code review: never a nonsense year silently accepted as a real date)', () => {
    expect(isValidDateParts(0, 1, 1)).toBe(false)
  })
})

describe('isValidYear — the single year rule shared by isValidDateParts and isValidMonthParts', () => {
  it('accepts any 4-digit calendar year from 0001 to 9999', () => {
    expect(isValidYear(1)).toBe(true)
    expect(isValidYear(2026)).toBe(true)
    expect(isValidYear(9999)).toBe(true)
  })

  it('rejects 0000 and anything outside the 1-9999 range', () => {
    expect(isValidYear(0)).toBe(false)
    expect(isValidYear(10000)).toBe(false)
    expect(isValidYear(-1)).toBe(false)
  })

  it('rejects a non-integer year', () => {
    expect(isValidYear(2026.5)).toBe(false)
  })
})

describe('isValidMonthParts', () => {
  it('accepts a real year/month combination', () => {
    expect(isValidMonthParts(2026, 11)).toBe(true)
  })

  it('rejects month 00 and month 13', () => {
    expect(isValidMonthParts(2026, 0)).toBe(false)
    expect(isValidMonthParts(2026, 13)).toBe(false)
  })

  it('rejects the year 0000 — exactly the MonthInput code-review finding (never commit a nonsense year as a real reporting month)', () => {
    expect(isValidMonthParts(0, 5)).toBe(false)
  })
})

describe('formatDateOnly', () => {
  it('formats the local calendar date portion of a JS Date as dd/MM/yyyy with English digits', () => {
    // Constructed from explicit local parts (not an ISO string with a zone offset) so this test is itself
    // independent of the runtime's time zone.
    expect(formatDateOnly(new Date(2026, 0, 5))).toBe('05/01/2026')
    expect(formatDateOnly(new Date(2026, 9, 20))).toBe('20/10/2026')
  })
})

describe('isoToParts / partsToIso round-trip', () => {
  it('parses a valid ISO date into parts', () => {
    expect(isoToParts('2026-10-05')).toEqual({ day: 5, month: 10, year: 2026 })
  })

  it('round-trips parts back to the same ISO string', () => {
    const parts = isoToParts('2024-02-29')
    expect(parts).toEqual({ day: 29, month: 2, year: 2024 })
    expect(partsToIso(parts!)).toBe('2024-02-29')
  })

  it('returns null for malformed input', () => {
    expect(isoToParts('2026/10/05')).toBeNull()
    expect(isoToParts('not-a-date')).toBeNull()
    expect(isoToParts(null)).toBeNull()
    expect(isoToParts(undefined)).toBeNull()
    expect(isoToParts('')).toBeNull()
  })

  it('returns null for a syntactically-ISO but calendar-invalid date', () => {
    expect(isoToParts('2023-02-29')).toBeNull()
    expect(isoToParts('2026-13-01')).toBeNull()
    expect(isoToParts('2026-04-31')).toBeNull()
  })

  it('partsToIso rejects an invalid/incomplete combination', () => {
    expect(partsToIso({ day: 31, month: 4, year: 2026 })).toBeNull()
    expect(partsToIso({ day: 29, month: 2, year: 2023 })).toBeNull()
    expect(partsToIso({ day: 5, month: 10 })).toBeNull()
    expect(partsToIso({})).toBeNull()
  })

  it('partsToIso zero-pads day and month', () => {
    expect(partsToIso({ day: 5, month: 1, year: 2026 })).toBe('2026-01-05')
  })
})

describe('sanitizeDigits', () => {
  it('passes Western digits through unchanged', () => {
    expect(sanitizeDigits('2026')).toBe('2026')
  })

  it('converts Arabic-Indic digits to Western digits', () => {
    expect(sanitizeDigits('٢٠٢٦')).toBe('2026')
    expect(sanitizeDigits('٠٥')).toBe('05')
  })

  it('strips non-digit characters', () => {
    expect(sanitizeDigits('12/34')).toBe('1234')
    expect(sanitizeDigits('ab12cd')).toBe('12')
  })
})

describe('formatIsoAsDisplayDate', () => {
  it('formats a valid ISO date as dd/MM/yyyy', () => {
    expect(formatIsoAsDisplayDate('2026-10-05')).toBe('05/10/2026')
  })

  it('formats a leap-day date correctly', () => {
    expect(formatIsoAsDisplayDate('2024-02-29')).toBe('29/02/2024')
  })

  it('returns null for invalid or missing input rather than mangling it', () => {
    expect(formatIsoAsDisplayDate(null)).toBeNull()
    expect(formatIsoAsDisplayDate(undefined)).toBeNull()
    expect(formatIsoAsDisplayDate('')).toBeNull()
    expect(formatIsoAsDisplayDate('not-iso')).toBeNull()
    expect(formatIsoAsDisplayDate('2023-02-29')).toBeNull()
  })
})

describe('formatDisplayDate', () => {
  it('formats a valid ISO date', () => {
    expect(formatDisplayDate('2026-10-05')).toBe('05/10/2026')
  })

  it('returns the fallback for a missing value', () => {
    expect(formatDisplayDate(null)).toBe('—')
    expect(formatDisplayDate(undefined, 'open-ended')).toBe('open-ended')
    expect(formatDisplayDate('', 'open-ended')).toBe('open-ended')
  })

  it('passes through a value that is not a valid ISO date rather than hiding it', () => {
    expect(formatDisplayDate('not-iso')).toBe('not-iso')
  })
})

describe('formatIsoMonthAsDisplay', () => {
  it('formats a valid YYYY-MM as MM/yyyy', () => {
    expect(formatIsoMonthAsDisplay('2026-11')).toBe('11/2026')
  })

  it('returns null for invalid or missing input', () => {
    expect(formatIsoMonthAsDisplay(null)).toBeNull()
    expect(formatIsoMonthAsDisplay(undefined)).toBeNull()
    expect(formatIsoMonthAsDisplay('2026-13')).toBeNull()
    expect(formatIsoMonthAsDisplay('2026')).toBeNull()
  })
})
