export const LOCALES = ['ar', 'en'] as const

export type Locale = (typeof LOCALES)[number]
export type Direction = 'rtl' | 'ltr'

export const DIRECTIONS: Record<Locale, Direction> = {
  ar: 'rtl',
  en: 'ltr',
}

export function isLocale(value: string): value is Locale {
  return (LOCALES as readonly string[]).includes(value)
}

/** Intl locale tag: Western (Latin) digits and the Gregorian calendar for every locale. */
export function toIntlLocale(locale: Locale): string {
  return `${locale}-u-nu-latn-ca-gregory`
}
