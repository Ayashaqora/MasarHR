import { render } from '@testing-library/react'
import { RouterProvider } from 'react-router'
import { vi } from 'vitest'
import { createTestRouter } from '../app/router'
import { AuthProvider } from '../features/auth/AuthProvider'
import type { CurrentPrincipal } from '../features/auth/api'
import type { AuthValue } from '../features/auth/context'
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

export const CURRENT_PRINCIPAL_BODY: CurrentPrincipal = {
  principal: { id: 'principal-1', username: 'admin', display_name: 'Admin One' },
  roles: [{ id: 'role-1', code: 'SECURITY_ADMINISTRATOR', name_ar: 'مسؤول الأمان', name_en: 'Security Administrator', is_active: true }],
  permissions: [
    'security.users.view',
    'security.users.create',
    'security.users.update',
    'security.users.status.manage',
    'security.roles.view',
    'security.roles.manage',
    'security.role_assignments.manage',
    'security.permissions.view',
  ],
}

/** Replaces window fetch; the handler receives the requested URL. */
export function stubFetch(handler: (url: string) => Promise<Response> | Response) {
  const fetchMock = vi.fn((input: RequestInfo | URL) => Promise.resolve(handler(String(input))))
  vi.stubGlobal('fetch', fetchMock)
  return fetchMock
}

/**
 * A URL-aware fetch stub for tests that render the whole app (and therefore trigger the auth
 * bootstrap: GET /auth/csrf-cookie then GET /auth/me). By default it simulates a fresh,
 * unauthenticated visitor; pass `authenticated: true` to simulate an already-signed-in session,
 * or `overrides` to intercept specific URLs before the defaults apply.
 */
export function stubAppFetch(options: {
  authenticated?: boolean
  overrides?: (url: string) => Response | Promise<Response> | undefined
} = {}) {
  const { authenticated = false, overrides } = options

  return stubFetch((url) => {
    const custom = overrides?.(url)
    if (custom) {
      return custom
    }
    if (url.includes('/auth/csrf-cookie')) {
      return new Response(null, { status: 204 })
    }
    if (url.includes('/auth/me')) {
      return authenticated
        ? jsonResponse(CURRENT_PRINCIPAL_BODY)
        : jsonResponse({ message: 'Unauthenticated.' }, 401)
    }
    if (url.includes('/auth/logout')) {
      return new Response(null, { status: 204 })
    }
    if (url.includes('/health')) {
      return jsonResponse(HEALTH_BODY)
    }
    return jsonResponse({ message: 'Not found.' }, 404)
  })
}

/**
 * A ready-made AuthValue for unit tests that render one feature component directly (not the
 * whole app), so they can provide auth state with <AuthContext.Provider> without going through
 * AuthProvider's own network bootstrap.
 */
export function fakeAuthValue(overrides: Partial<AuthValue> = {}): AuthValue {
  return {
    status: 'authenticated',
    principal: { id: 'principal-1', username: 'admin', display_name: 'Admin One' },
    roles: [],
    permissions: [],
    hasPermission: (code) => (overrides.permissions ?? []).includes(code),
    login: vi.fn(),
    logout: vi.fn(),
    sessionExpired: vi.fn(),
    ...overrides,
  }
}

export function renderApp(path = '/', locale: Locale = 'ar') {
  return render(
    <I18nProvider initialLocale={locale}>
      <AuthProvider>
        <RouterProvider router={createTestRouter([path])} />
      </AuthProvider>
    </I18nProvider>,
  )
}
