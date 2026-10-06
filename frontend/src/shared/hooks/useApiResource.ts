import { useCallback, useEffect, useState } from 'react'
import { ApiError, normalizeApiError } from '../api'

type Settled<T> = { attempt: number; data: T } | { attempt: number; error: ApiError }

export type ApiResourceState<T> =
  | { status: 'loading' }
  | { status: 'success'; data: T }
  | { status: 'error'; error: ApiError }

/**
 * Generalizes the loading/success/error/retry shape already established by useHealthCheck
 * (features/system-status) and the security-admin module's own useList — S18 (Employee 360)
 * needs the identical state machine for several independent resources (person, employment
 * relationships, status periods, placement periods, full-secondment periods, workplace-assignment
 * periods, actual workplace, organizational units, employment-status-detail catalog), so this one
 * implementation is reused rather than re-declared per resource.
 *
 * `deps` re-runs the fetch when route params change (e.g. navigating from one employee to
 * another) — every S18 hook built on this passes the relevant ids so a deep link or an in-app
 * navigation always fetches the newly-selected resource rather than showing stale data.
 */
export function useApiResource<T>(
  fetcher: ((signal: AbortSignal) => Promise<T>) | null,
  deps: readonly unknown[],
): ApiResourceState<T> & { retry: () => void } {
  const [attempt, setAttempt] = useState(0)
  const [settled, setSettled] = useState<Settled<T> | null>(null)

  /*
   * `deps` cannot be spread directly into useEffect's own dependency array below: React requires
   * that array to have the SAME LENGTH on every render of a given effect call site, but
   * useOrganizationalUnitNames (features/employees/hooks.ts) passes a variable-length array of
   * unit ids that grows from 0 to however many distinct ids the other S18 resources resolve to.
   * Re-verifying after the BLOCKING-finding fix below surfaced exactly this: React's own dev
   * warning ("The final argument passed to useEffect changed size between renders") and 4
   * deterministic Employee360Page test failures, because the effect stopped firing correctly once
   * the length changed. Serializing `deps` to a single, fixed-shape string key sidesteps the
   * problem entirely — the effect's dependency array below is always exactly two elements
   * ([attempt, depsKey]) no matter how many entries the caller's `deps` itself has, while still
   * changing value (and so still re-running the effect) whenever any entry's value changes.
   */
  const depsKey = JSON.stringify(deps)

  useEffect(() => {
    if (fetcher === null) {
      // Nothing to fetch. `settled` may still hold a previous fetcher's result, but the read
      // below always checks `fetcher === null` first and returns 'loading' unconditionally in
      // that case, so a stale value here is never observed by a caller.
      return
    }

    const controller = new AbortController()

    fetcher(controller.signal)
      .then((data) => {
        // Mirrors the .catch guard below: a stale request can still resolve WITH data after this
        // effect's own cleanup already called controller.abort() (e.g. the response had already
        // arrived before abort() took effect) — not every environment rejects a cancelled fetch
        // in time. Without this check that late resolution would silently overwrite a newer
        // request's already-rendered result, since `attempt` alone does not distinguish between
        // two fetches triggered by a deps change (only retry() changes it) — a real race caught
        // by the consolidated S47 review and reproduced in FollowUpsPage.test.tsx.
        if (!controller.signal.aborted) {
          setSettled({ attempt, data })
        }
      })
      .catch((error: unknown) => {
        if (!controller.signal.aborted) {
          setSettled({ attempt, error: normalizeApiError(error) })
        }
      })

    return () => controller.abort()
    // `fetcher` is deliberately NOT a dependency here: every caller in features/employees/hooks.ts
    // (and useHealthCheck/useList's own established pattern) passes a freshly-constructed, un-
    // memoized arrow function on every render, so including it would re-run this effect on every
    // render that this effect itself causes (setSettled → re-render → new fetcher → effect fires
    // → new fetch → setSettled → …), an unbounded refetch loop — a real, verified defect an
    // independent adversarial review caught (S18 adversarial review, BLOCKING finding #1) by
    // instrumenting a call counter: 63 duplicate requests to one endpoint within 850ms with
    // `fetcher` included. `depsKey` (derived from `deps`, see above) carries every value the
    // fetcher's closure actually varies over (ids, the search query, …), so it alone is sufficient
    // to decide when a genuinely new fetch is needed; `fetcher`'s identity is not a meaningful
    // signal on its own.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [attempt, depsKey])

  const retry = useCallback(() => setAttempt((current) => current + 1), [])

  if (fetcher === null || !settled || settled.attempt !== attempt) {
    return { status: 'loading', retry }
  }
  if ('error' in settled) {
    return { status: 'error', error: settled.error, retry }
  }
  return { status: 'success', data: settled.data, retry }
}
