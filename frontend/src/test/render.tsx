import { render } from '@testing-library/react'
import { RouterProvider } from 'react-router'
import { vi } from 'vitest'
import { createTestRouter } from '../app/router'
import { I18nProvider } from '../i18n/I18nProvider'
import type { Locale } from '../i18n/locales'

export function jsonResponse(body: unknown, status = 200): Response {
  return new Response(JSON.stringify(body), {
    status,
    headers: { 'Content-Type': 'application/json' },
  })
}

export const HEALTH_BODY = {
  status: 'ok',
  service: 'masar-hr-api',
  version: 'v1',
  timestamp: '2026-01-15T10:30:00+00:00',
}

/** Replaces window fetch; the handler receives the requested URL. */
export function stubFetch(handler: (url: string) => Promise<Response> | Response) {
  const fetchMock = vi.fn((input: RequestInfo | URL) => Promise.resolve(handler(String(input))))
  vi.stubGlobal('fetch', fetchMock)
  return fetchMock
}

export function renderApp(path = '/', locale: Locale = 'ar') {
  return render(
    <I18nProvider initialLocale={locale}>
      <RouterProvider router={createTestRouter([path])} />
    </I18nProvider>,
  )
}
