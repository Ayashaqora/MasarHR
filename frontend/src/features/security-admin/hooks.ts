import { useCallback, useEffect, useState } from 'react'
import { ApiError, normalizeApiError } from '../../shared/api'
import {
  fetchPermissions,
  fetchPrincipals,
  fetchRoles,
  type PermissionSummary,
  type PrincipalSummary,
  type RoleSummary,
} from './api'

type Settled<T> = { attempt: number; data: T } | { attempt: number; error: ApiError }

export type ListState<T> =
  | { status: 'loading' }
  | { status: 'success'; data: T }
  | { status: 'error'; error: ApiError }

/** Same loading/success/error/retry shape as useHealthCheck, generalized for the list endpoints. */
function useList<T>(fetcher: (signal: AbortSignal) => Promise<T>): ListState<T> & { retry: () => void } {
  const [attempt, setAttempt] = useState(0)
  const [settled, setSettled] = useState<Settled<T> | null>(null)

  useEffect(() => {
    const controller = new AbortController()

    fetcher(controller.signal)
      .then((data) => setSettled({ attempt, data }))
      .catch((error: unknown) => {
        if (!controller.signal.aborted) {
          setSettled({ attempt, error: normalizeApiError(error) })
        }
      })

    return () => controller.abort()
  }, [attempt, fetcher])

  const retry = useCallback(() => setAttempt((current) => current + 1), [])

  if (!settled || settled.attempt !== attempt) {
    return { status: 'loading', retry }
  }
  if ('error' in settled) {
    return { status: 'error', error: settled.error, retry }
  }
  return { status: 'success', data: settled.data, retry }
}

export function usePrincipals(): ListState<PrincipalSummary[]> & { retry: () => void } {
  return useList(fetchPrincipals)
}

export function useRoles(): ListState<RoleSummary[]> & { retry: () => void } {
  return useList(fetchRoles)
}

export function usePermissions(): ListState<PermissionSummary[]> & { retry: () => void } {
  return useList(fetchPermissions)
}
