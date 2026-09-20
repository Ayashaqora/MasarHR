import { isLocale, type Locale } from '../../i18n/locales'

const DEFAULT_API_BASE_URL = '/api/v1'

function resolveDefaultLocale(value: string | undefined): Locale {
  return value && isLocale(value) ? value : 'ar'
}

/** Single source of runtime configuration. Components must not read import.meta.env directly. */
export const env = {
  apiBaseUrl: (import.meta.env.VITE_API_BASE_URL || DEFAULT_API_BASE_URL).replace(/\/+$/, ''),
  defaultLocale: resolveDefaultLocale(import.meta.env.VITE_DEFAULT_LOCALE),
  requestTimeoutMs: 15_000,
} as const
