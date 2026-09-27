import { apiRequest } from '../../shared/api'

export interface CurrentPrincipalRole {
  id: string
  code: string
  name_ar: string
  name_en: string
  is_active: boolean
}

/** The GET /api/v1/auth/me shape (§21): identity, safe role info, effective permission codes. */
export interface CurrentPrincipal {
  principal: { id: string; username: string; display_name: string }
  roles: CurrentPrincipalRole[]
  permissions: string[]
}

/**
 * Primes the XSRF-TOKEN cookie. Laravel's CSRF middleware only attaches it to a response that
 * completes successfully — an unauthenticated /auth/me never does — so the frontend calls this
 * once at startup, before anything that might need to send the token back (login included).
 */
export function fetchCsrfCookie(signal?: AbortSignal): Promise<void> {
  return apiRequest<void>('/auth/csrf-cookie', signal ? { signal } : {})
}

export function fetchCurrentPrincipal(signal?: AbortSignal): Promise<CurrentPrincipal> {
  return apiRequest<CurrentPrincipal>('/auth/me', signal ? { signal } : {})
}

export function login(username: string, password: string): Promise<CurrentPrincipal> {
  return apiRequest<CurrentPrincipal>('/auth/login', { method: 'POST', body: { username, password } })
}

export function logout(): Promise<void> {
  return apiRequest<void>('/auth/logout', { method: 'POST' })
}
