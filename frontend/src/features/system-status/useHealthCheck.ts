import { useCallback, useEffect, useState } from 'react'
import { ApiError, normalizeApiError } from '../../shared/api'
import { fetchHealth, type HealthResponse } from './api'

type Settled = { attempt: number; data: HealthResponse } | { attempt: number; error: ApiError }

export type HealthCheckState =
  | { status: 'loading' }
  | { status: 'success'; data: HealthResponse }
  | { status: 'error'; error: ApiError }

export function useHealthCheck(): HealthCheckState & { retry: () => void } {
  const [attempt, setAttempt] = useState(0)
  const [settled, setSettled] = useState<Settled | null>(null)

  useEffect(() => {
    const controller = new AbortController()

    fetchHealth(controller.signal)
      .then((data) => setSettled({ attempt, data }))
      .catch((error: unknown) => {
        if (!controller.signal.aborted) {
          setSettled({ attempt, error: normalizeApiError(error) })
        }
      })

    return () => controller.abort()
  }, [attempt])

  const retry = useCallback(() => setAttempt((current) => current + 1), [])

  if (!settled || settled.attempt !== attempt) {
    return { status: 'loading', retry }
  }
  if ('error' in settled) {
    return { status: 'error', error: settled.error, retry }
  }
  return { status: 'success', data: settled.data, retry }
}
