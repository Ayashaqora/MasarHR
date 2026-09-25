import { useCallback, useEffect, useMemo, useState, type ReactNode } from 'react'
import {
  fetchCsrfCookie,
  fetchCurrentPrincipal,
  login as loginRequest,
  logout as logoutRequest,
  type CurrentPrincipal,
} from './api'
import { AuthContext, type AuthStatus, type AuthValue } from './context'

/**
 * Bootstraps the authenticated session on app load (§23), and is the single source of truth for
 * "who is the current principal" everywhere else in the app. Authentication and authorization are
 * kept separate on purpose (§15): this only tracks identity and the effective-permission codes
 * the backend returned — it never itself decides whether an action is allowed, it just gives
 * components a cheap way to ask.
 */
export function AuthProvider({ children }: { children: ReactNode }) {
  const [status, setStatus] = useState<AuthStatus>('bootstrapping')
  const [session, setSession] = useState<CurrentPrincipal | null>(null)

  useEffect(() => {
    const controller = new AbortController()

    async function bootstrap() {
      try {
        await fetchCsrfCookie(controller.signal)
      } catch {
        // Priming the cookie ahead of time is a convenience, not a precondition: if it fails, the
        // first mutating request simply arrives without a token and is rejected by the backend,
        // which the caller already has to handle.
      }

      try {
        const current = await fetchCurrentPrincipal(controller.signal)
        if (!controller.signal.aborted) {
          setSession(current)
          setStatus('authenticated')
        }
      } catch {
        if (!controller.signal.aborted) {
          setSession(null)
          setStatus('unauthenticated')
        }
      }
    }

    void bootstrap()
    return () => controller.abort()
  }, [])

  const login = useCallback(async (username: string, password: string) => {
    const current = await loginRequest(username, password)
    setSession(current)
    setStatus('authenticated')
  }, [])

  const logout = useCallback(async () => {
    try {
      await logoutRequest()
    } finally {
      setSession(null)
      setStatus('unauthenticated')
    }
  }, [])

  const sessionExpired = useCallback(() => {
    setSession(null)
    setStatus('unauthenticated')
  }, [])

  const permissions = useMemo(() => session?.permissions ?? [], [session])
  const hasPermission = useCallback((code: string) => permissions.includes(code), [permissions])

  const value = useMemo<AuthValue>(
    () => ({
      status,
      principal: session?.principal ?? null,
      roles: session?.roles ?? [],
      permissions,
      hasPermission,
      login,
      logout,
      sessionExpired,
    }),
    [status, session, permissions, hasPermission, login, logout, sessionExpired],
  )

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>
}
