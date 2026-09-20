import { useEffect, useMemo, useState, type ReactNode } from 'react'
import { env } from '../shared/config/env'
import { I18nContext, type I18nValue } from './context'
import { DIRECTIONS, toIntlLocale, type Locale } from './locales'
import { ar } from './messages/ar'
import { en } from './messages/en'
import type { Messages } from './messages/types'

const CATALOGS: Record<Locale, Messages> = { ar, en }

export function I18nProvider({
  children,
  initialLocale = env.defaultLocale,
}: {
  children: ReactNode
  initialLocale?: Locale
}) {
  const [locale, setLocale] = useState<Locale>(initialLocale)
  const dir = DIRECTIONS[locale]

  useEffect(() => {
    const root = document.documentElement
    root.lang = locale
    root.dir = dir
    document.title = CATALOGS[locale].app.documentTitle
  }, [locale, dir])

  const value = useMemo<I18nValue>(
    () => ({
      locale,
      dir,
      messages: CATALOGS[locale],
      intlLocale: toIntlLocale(locale),
      setLocale,
    }),
    [locale, dir],
  )

  return <I18nContext.Provider value={value}>{children}</I18nContext.Provider>
}
