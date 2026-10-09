import { renderHook, waitFor } from '@testing-library/react'
import { act } from 'react'
import { describe, expect, it } from 'vitest'
import { useApiResource } from './useApiResource'

/**
 * Dedicated, UI-independent proof of the abort-guard documented inline in useApiResource.ts
 * itself (the `if (!controller.signal.aborted)` checks): a request whose `deps` change before it
 * resolves must never let a late, real-data response overwrite the newer request's already-
 * rendered result. FollowUpsPage.test.tsx exercises this same property through a live, same-
 * mounted-component UI (its state filter never unmounts), which is why it is a faithful live-race
 * reproduction there. An independent S49 review confirmed that none of the new qualification-
 * history panels (docs/person-qualification-history-ui-specification.md) offer an equivalent
 * same-instance opportunity through their own real UI -- every identity change there (closing a
 * Sheet, switching the viewed person) is mediated by a full unmount, and a repeated click within
 * one still-mounted panel cannot target a different page before the in-flight request resolves
 * (QualificationArchivePagination's Prev/Next targets are computed from the last-RENDERED, not-
 * yet-updated `meta`, so a second click before resolution recomputes the SAME target page and
 * never starts a second, differently-targeted request). This test instead exercises the shared
 * hook directly, the one place in this codebase where the guarantee is general-purpose and reused
 * by every current and future caller. useApiResource.ts itself is NOT modified -- only this new
 * test file is added.
 */
describe('useApiResource — stale response race', () => {
  it('discards a stale response that resolves WITH data after a newer one, when deps change while the first request is still pending', async () => {
    const stale: { resolve: ((value: string) => void) | null } = { resolve: null }
    let staleAborted = false

    function fetcherFor(id: string) {
      return (signal: AbortSignal): Promise<string> => {
        if (id === 'first') {
          // Deliberately does NOT reject on abort -- reproducing a real fetch whose response had
          // already arrived server-side before abort() took local effect (the same scenario
          // FollowUpsPage.test.tsx's second stale-response test reproduces).
          return new Promise<string>((resolve) => {
            stale.resolve = resolve
            signal.addEventListener('abort', () => {
              staleAborted = true
            })
          })
        }
        return Promise.resolve(`data-for-${id}`)
      }
    }

    const { result, rerender } = renderHook(({ id }: { id: string }) => useApiResource(fetcherFor(id), [id]), {
      initialProps: { id: 'first' },
    })

    expect(result.current.status).toBe('loading')

    // The deps change (id: 'first' -> 'second') BEFORE the first request resolves -- this is the
    // same mounted hook instance throughout, no unmount involved anywhere in this test.
    rerender({ id: 'second' })

    await waitFor(() => expect(result.current).toMatchObject({ status: 'success', data: 'data-for-second' }))
    expect(staleAborted).toBe(true)

    // Only now does the original ('first') request resolve, late, with real data of its own. It
    // must never be allowed to overwrite the already-rendered newer ('second') result.
    await act(async () => {
      stale.resolve?.('data-for-first')
      await Promise.resolve()
    })

    expect(result.current).toMatchObject({ status: 'success', data: 'data-for-second' })
  })
})
