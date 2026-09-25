import { createContext, useContext } from 'react'
import type { CurrentPrincipalRole } from './api'

/**
 * 'bootstrapping' — the initial GET /auth/me is still in flight; nothing about the caller is
 * known yet, so protected UI must not assume either authenticated or unauthenticated.
 */
export type AuthStatus = 'bootstrapping' | 'authenticated' | 'unauthenticated'

export interface AuthValue {
  status: AuthStatus
  principal: { id: string; username: string; display_name: string } | null
  roles: CurrentPrincipalRole[]
  permissions: string[]
  /** UX only — the backend re-checks every permission on every request (§15/SEC-04). */
  hasPermission: (code: string) => boolean
  login: (username: string, password: string) => Promise<void>
  logout: () => Promise<void>
  /**
   * Call this when a request the app believed was authenticated comes back 401 (the account was
   * disabled, or the session expired/was invalidated elsewhere). It moves the app straight to the
   * unauthenticated state so the next render redirects to /login, instead of waiting for a full
   * page reload to notice.
   */
  sessionExpired: () => void
}

export const AuthContext = createContext<AuthValue | null>(null)

export function useAuth(): AuthValue {
  const value = useContext(AuthContext)
  if (!value) {
    throw new Error('useAuth must be used inside <AuthProvider>.')
  }
  return value
}
