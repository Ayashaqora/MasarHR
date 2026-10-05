import { useCallback, useRef, useState } from 'react'
import { useAuth } from '../../features/auth/context'
import { useI18n } from '../../i18n/context'
import { normalizeApiError } from '../api'
import { describeMutationFailure, type MutationFailure } from '../api/mutationError'

/**
 * One write operation's lifecycle: pending (guards against a double submit), the last failure, and a
 * `run` that resolves `true` only when the backend accepted the write. It never fabricates domain state and
 * never retries: a 401 moves the app to the unauthenticated state (like every other request), and every
 * other failure is surfaced for the user to act on.
 */
export function useOperation<TArgs extends unknown[]>(send: (...args: TArgs) => Promise<unknown>) {
  const { messages } = useI18n()
  const { sessionExpired } = useAuth()
  const [pending, setPending] = useState(false)
  const [failure, setFailure] = useState<MutationFailure | null>(null)
  const inFlight = useRef(false)

  const run = useCallback(
    async (...args: TArgs): Promise<boolean> => {
      if (inFlight.current) {
        return false
      }
      inFlight.current = true
      setPending(true)
      setFailure(null)
      try {
        await send(...args)
        return true
      } catch (caught) {
        const error = normalizeApiError(caught)
        if (error.status === 401) {
          sessionExpired()
        }
        setFailure(describeMutationFailure(error, messages))
        return false
      } finally {
        inFlight.current = false
        setPending(false)
      }
    },
    // `send` is a fresh closure per render by design; only the language/session helpers matter for identity.
    // eslint-disable-next-line react-hooks/exhaustive-deps
    [messages, sessionExpired],
  )

  const clearFailure = useCallback(() => setFailure(null), [])

  return { run, pending, failure, clearFailure }
}
