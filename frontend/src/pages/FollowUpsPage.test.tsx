import { act, fireEvent, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it, vi } from 'vitest'
import type { CurrentPrincipal } from '../features/auth/api'
import type { EmploymentStatusExpiryFollowUp, FollowUpPage, MovementExpiryFollowUp } from '../features/followUps/api'
import { CURRENT_PRINCIPAL_BODY, jsonResponse, renderApp, stubAppFetch } from '../test/render'

const MOVEMENT_VIEW = 'hr.movement_expiry_followups.view'
const STATUS_VIEW = 'hr.employment_status_expiry_followups.view'

function principalWith(permissions: string[]): CurrentPrincipal {
  return { ...CURRENT_PRINCIPAL_BODY, permissions }
}

function moveRow(overrides: Partial<MovementExpiryFollowUp> = {}): MovementExpiryFollowUp {
  return {
    id: 'move-1',
    followup_kind: 'EXPIRY_WARNING_7D',
    movement_type: 'FULL_SECONDMENT',
    movement_id: 'movement-1',
    employment_relationship_id: 'rel-1',
    organizational_unit_id: 'unit-1',
    expected_effective_to: '2026-11-15',
    due_date: '2026-11-08',
    status: 'ACTIONABLE',
    state: 'ACTIONABLE',
    suppression_reason: null,
    created_at: '2026-11-01T00:00:00Z',
    suppressed_at: null,
    ...overrides,
  }
}

function statusRow(overrides: Partial<EmploymentStatusExpiryFollowUp> = {}): EmploymentStatusExpiryFollowUp {
  return {
    id: 'status-1',
    followup_kind: 'EXPIRY_WARNING_7D',
    employment_status_period_id: 'period-1',
    employment_relationship_id: 'rel-2',
    expected_effective_to: '2026-11-20',
    due_date: '2026-11-13',
    status: 'ACTIONABLE',
    state: 'ACTIONABLE',
    suppression_reason: null,
    created_at: '2026-11-01T00:00:00Z',
    suppressed_at: null,
    ...overrides,
  }
}

function page<T>(rows: T[], total = rows.length): FollowUpPage<T> {
  return { data: rows, meta: { current_page: 1, last_page: 1, per_page: 50, total } }
}

function pageMeta<T>(rows: T[], currentPage: number, lastPage: number, total: number): FollowUpPage<T> {
  return { data: rows, meta: { current_page: currentPage, last_page: lastPage, per_page: 50, total } }
}

function stubFollowUps(options: {
  principal: CurrentPrincipal
  movement?: (url: string) => Response
  status?: (url: string) => Response
}) {
  return stubAppFetch({
    overrides: (url) => {
      if (url.includes('/auth/me')) {
        return jsonResponse(options.principal)
      }
      if (url.includes('/hr/movement-expiry-followups')) {
        return options.movement ? options.movement(url) : jsonResponse(page([moveRow()]))
      }
      if (url.includes('/hr/employment-status-expiry-followups')) {
        return options.status ? options.status(url) : jsonResponse(page([statusRow()]))
      }
      return undefined
    },
  })
}

const businessUrls = (fetchMock: ReturnType<typeof stubFollowUps>, path: string) =>
  fetchMock.mock.calls.map((call) => String(call[0])).filter((url) => url.includes(path))

afterEach(() => {
  vi.useRealTimers()
})

describe('Follow-ups page — permission gating', () => {
  it('shows both tabs for a principal holding both permissions, movement active by default, and requests ONLY the active tab', async () => {
    const fetchMock = stubFollowUps({ principal: principalWith([MOVEMENT_VIEW, STATUS_VIEW]) })
    renderApp('/follow-ups')

    await screen.findByRole('tab', { name: 'متابعة انتهاء الحركات' })
    await screen.findByRole('tab', { name: 'متابعة انتهاء الحالات الوظيفية' })
    await screen.findByText('انتداب كلي')

    expect(businessUrls(fetchMock, '/hr/movement-expiry-followups')).toHaveLength(1)
    expect(businessUrls(fetchMock, '/hr/employment-status-expiry-followups')).toHaveLength(0)
  })

  it('switches to the status tab on click and requests it exactly once, without re-requesting movement', async () => {
    const fetchMock = stubFollowUps({ principal: principalWith([MOVEMENT_VIEW, STATUS_VIEW]) })
    const user = userEvent.setup()
    renderApp('/follow-ups')
    await screen.findByText('انتداب كلي')

    await user.click(screen.getByRole('tab', { name: 'متابعة انتهاء الحالات الوظيفية' }))

    await waitFor(() => expect(businessUrls(fetchMock, '/hr/employment-status-expiry-followups')).toHaveLength(1))
    expect(screen.getByRole('tab', { name: 'متابعة انتهاء الحالات الوظيفية' })).toHaveAttribute('aria-selected', 'true')
    await screen.findByRole('table')
    expect(businessUrls(fetchMock, '/hr/movement-expiry-followups')).toHaveLength(1)
  })

  it('shows only the movement section (no tabs) for a principal holding only the movement permission, and never requests the status endpoint', async () => {
    const fetchMock = stubFollowUps({ principal: principalWith([MOVEMENT_VIEW]) })
    renderApp('/follow-ups')

    await screen.findByText('انتداب كلي')
    expect(screen.queryByRole('tab', { name: 'متابعة انتهاء الحركات' })).not.toBeInTheDocument()
    expect(businessUrls(fetchMock, '/hr/employment-status-expiry-followups')).toHaveLength(0)
  })

  it('shows only the status section for a principal holding only the status permission, and never requests the movement endpoint', async () => {
    const fetchMock = stubFollowUps({ principal: principalWith([STATUS_VIEW]) })
    renderApp('/follow-ups')

    await waitFor(() => expect(businessUrls(fetchMock, '/hr/employment-status-expiry-followups')).toHaveLength(1))
    expect(businessUrls(fetchMock, '/hr/movement-expiry-followups')).toHaveLength(0)
    expect(screen.queryByRole('tab')).not.toBeInTheDocument()
  })

  it('shows the unauthorized panel and makes NO follow-up request for a principal holding neither permission', async () => {
    const fetchMock = stubFollowUps({ principal: principalWith([]) })
    renderApp('/follow-ups')

    await screen.findByText('لا تملك صلاحية الوصول')
    expect(businessUrls(fetchMock, '/hr/movement-expiry-followups')).toHaveLength(0)
    expect(businessUrls(fetchMock, '/hr/employment-status-expiry-followups')).toHaveLength(0)
  })
})

describe('Follow-ups page — state filter', () => {
  it('requests the selected state and never renders a response for a previously-selected filter value', async () => {
    const fetchMock = stubFollowUps({
      principal: principalWith([MOVEMENT_VIEW]),
      movement: (url) => {
        if (url.includes('state=SUPPRESSED')) {
          return jsonResponse(page([moveRow({ id: 'move-suppressed', status: 'SUPPRESSED', state: 'SUPPRESSED', suppression_reason: 'RELATIONSHIP_ENDED' })]))
        }
        return jsonResponse(page([moveRow()]))
      },
    })
    renderApp('/follow-ups')
    await screen.findByText('انتداب كلي')

    fireEvent.change(screen.getByLabelText('الحالة'), { target: { value: 'SUPPRESSED' } })

    const table = await screen.findByRole('table')
    await waitFor(() => expect(within(table).getByText('انتهت علاقة العمل')).toBeInTheDocument())
    // The option list also contains the literal string 'مستحقة' (stateActionable), so the staleness
    // check is scoped to the table body itself, never to the whole document.
    expect(within(table).queryByText('مستحقة')).not.toBeInTheDocument()

    const calls = businessUrls(fetchMock, '/hr/movement-expiry-followups')
    expect(calls.at(-1)).toContain('state=SUPPRESSED')
  })

  it('shows the matching empty-state copy per filter when the list has no rows', async () => {
    stubFollowUps({ principal: principalWith([STATUS_VIEW]), status: () => jsonResponse(page<EmploymentStatusExpiryFollowUp>([], 0)) })
    renderApp('/follow-ups')

    await screen.findByText('لا توجد متابعات مستحقة.')
  })
})

describe('Follow-ups page — pagination', () => {
  it('requests the clicked page, disables Next/Previous at the bounds, and resets to page 1 on filter change', async () => {
    const responses: Record<string, FollowUpPage<MovementExpiryFollowUp>> = {
      'ACTIONABLE:1': pageMeta([moveRow({ id: 'move-a1' })], 1, 3, 120),
      'ACTIONABLE:2': pageMeta([moveRow({ id: 'move-a2' })], 2, 3, 120),
      'ACTIONABLE:3': pageMeta([moveRow({ id: 'move-a3' })], 3, 3, 120),
      'SUPPRESSED:1': pageMeta(
        [moveRow({ id: 'move-s1', status: 'SUPPRESSED', state: 'SUPPRESSED', suppression_reason: 'RELATIONSHIP_ENDED' })],
        1,
        1,
        1,
      ),
    }
    const fetchMock = stubFollowUps({
      principal: principalWith([MOVEMENT_VIEW]),
      movement: (url) => {
        const state = /state=([A-Z]+)/.exec(url)?.[1] ?? 'ACTIONABLE'
        const requestedPage = /page=(\d+)/.exec(url)?.[1] ?? '1'
        return jsonResponse(responses[`${state}:${requestedPage}`] ?? pageMeta([], 1, 1, 0))
      },
    })
    const user = userEvent.setup()
    renderApp('/follow-ups')
    await screen.findByText('انتداب كلي')

    const previousButton = () => screen.getByRole('button', { name: 'السابق' })
    const nextButton = () => screen.getByRole('button', { name: 'التالي' })

    // Page 1 of 3: at the lower bound, Previous is disabled and Next is not.
    expect(previousButton()).toBeDisabled()
    expect(nextButton()).not.toBeDisabled()
    await screen.findByText('1–50')

    await user.click(nextButton())
    await waitFor(() => expect(businessUrls(fetchMock, '/hr/movement-expiry-followups').at(-1)).toContain('page=2'))
    await screen.findByText('51–100')
    expect(previousButton()).not.toBeDisabled()
    expect(nextButton()).not.toBeDisabled()

    await user.click(nextButton())
    await waitFor(() => expect(businessUrls(fetchMock, '/hr/movement-expiry-followups').at(-1)).toContain('page=3'))
    await screen.findByText('101–120')
    // Page 3 of 3: at the upper bound, Next is disabled and Previous is not.
    expect(nextButton()).toBeDisabled()
    expect(previousButton()).not.toBeDisabled()

    await user.click(previousButton())
    await waitFor(() => expect(businessUrls(fetchMock, '/hr/movement-expiry-followups').at(-1)).toContain('page=2'))
    await screen.findByText('51–100')

    // Changing the filter resets to page 1: the next request is page=1 of the NEW state, never a
    // continuation of the page the viewer was on before switching filters.
    fireEvent.change(screen.getByLabelText('الحالة'), { target: { value: 'SUPPRESSED' } })
    await waitFor(() => {
      const last = businessUrls(fetchMock, '/hr/movement-expiry-followups').at(-1)
      expect(last).toContain('state=SUPPRESSED')
      expect(last).toContain('page=1')
    })
    const table = await screen.findByRole('table')
    await waitFor(() => expect(within(table).getByText('انتهت علاقة العمل')).toBeInTheDocument())
    // The new filter's own single page has nothing to page through.
    expect(nextButton()).toBeDisabled()
    expect(previousButton()).toBeDisabled()
  })
})

describe('Follow-ups page — stale response race', () => {
  it('cancels the stale request when the filter changes, so its later rejection never reaches the newer result, via the AbortController useApiResource aborts in its own cleanup', async () => {
    // A plain `let` reassigned only inside the Promise executor below narrows to `null` in
    // TypeScript's flow analysis at the call site (a known cross-closure limitation), so this
    // uses a mutable holder instead — an object property isn't narrowed the same way.
    const stale: { resolve: (() => void) | null } = { resolve: null }

    const fetchMock = vi.fn((input: RequestInfo | URL, init?: RequestInit) => {
      const url = String(input)
      if (url.includes('/auth/csrf-cookie')) {
        return Promise.resolve(new Response(null, { status: 204 }))
      }
      if (url.includes('/auth/me')) {
        return Promise.resolve(jsonResponse(principalWith([MOVEMENT_VIEW])))
      }
      if (url.includes('/hr/movement-expiry-followups')) {
        if (url.includes('state=SUPPRESSED')) {
          return Promise.resolve(
            jsonResponse(
              page([
                moveRow({
                  id: 'move-fresh',
                  movement_type: 'WORKPLACE_ASSIGNMENT',
                  status: 'SUPPRESSED',
                  state: 'SUPPRESSED',
                  suppression_reason: 'RELATIONSHIP_ENDED',
                }),
              ]),
            ),
          )
        }
        // The initial ACTIONABLE request: settles only when resolveStale() is called below, OR
        // when the component's cleanup aborts it on the filter change (a real AbortController-
        // backed fetch rejects immediately on abort, well before any late server response could
        // arrive) — whichever happens first. This reproduces that real race instead of assuming
        // the abort protection away.
        return new Promise<Response>((resolve, reject) => {
          stale.resolve = () => resolve(jsonResponse(page([moveRow({ id: 'move-stale' })])))
          init?.signal?.addEventListener('abort', () => reject(new DOMException('Aborted', 'AbortError')))
        })
      }
      return Promise.resolve(jsonResponse({ message: 'Not found.' }, 404))
    })
    vi.stubGlobal('fetch', fetchMock)

    renderApp('/follow-ups')
    await screen.findByText('جارٍ تحميل المتابعات…')

    fireEvent.change(screen.getByLabelText('الحالة'), { target: { value: 'SUPPRESSED' } })

    const table = await screen.findByRole('table')
    await waitFor(() => expect(within(table).getByText('تكليف')).toBeInTheDocument())

    // The stale ACTIONABLE response "arrives" only now, after the fresh SUPPRESSED one already
    // rendered. The filter change already aborted it, so this resolves an already-rejected
    // promise and has no effect.
    stale.resolve?.()
    await new Promise((resolve) => setTimeout(resolve, 0))

    expect(within(table).getByText('تكليف')).toBeInTheDocument()
    expect(within(table).queryByText('انتداب كلي')).not.toBeInTheDocument()
  })

  it('discards a stale response that resolves WITH data after a newer one, even though the filter change already triggered cancellation', async () => {
    // Checks useApiResource's existing protection rather than assuming it: unlike the sibling test
    // above (where the stale request's promise rejects once aborted), this one lets it complete
    // normally — reproducing a fetch that still resolves after cancellation (e.g. the response
    // had already arrived before abort() took effect), which is the scenario the original
    // .then()-only guard in useApiResource did not cover.
    const stale: { resolve: (() => void) | null } = { resolve: null }
    let staleRequestAborted = false

    const fetchMock = vi.fn((input: RequestInfo | URL, init?: RequestInit) => {
      const url = String(input)
      if (url.includes('/auth/csrf-cookie')) {
        return Promise.resolve(new Response(null, { status: 204 }))
      }
      if (url.includes('/auth/me')) {
        return Promise.resolve(jsonResponse(principalWith([MOVEMENT_VIEW])))
      }
      if (url.includes('/hr/movement-expiry-followups')) {
        if (url.includes('state=SUPPRESSED')) {
          return Promise.resolve(
            jsonResponse(
              page([
                moveRow({
                  id: 'move-fresh',
                  movement_type: 'WORKPLACE_ASSIGNMENT',
                  status: 'SUPPRESSED',
                  state: 'SUPPRESSED',
                  suppression_reason: 'RELATIONSHIP_ENDED',
                }),
              ]),
            ),
          )
        }
        // The initial ACTIONABLE request: deliberately does NOT reject on abort. It stays pending
        // until resolveStale() is called below, well after the fresh SUPPRESSED response already
        // rendered, and completes WITH data regardless of the cancellation useApiResource's
        // cleanup already performed.
        return new Promise<Response>((resolve) => {
          stale.resolve = () => resolve(jsonResponse(page([moveRow({ id: 'move-stale' })])))
          init?.signal?.addEventListener('abort', () => {
            staleRequestAborted = true
          })
        })
      }
      return Promise.resolve(jsonResponse({ message: 'Not found.' }, 404))
    })
    vi.stubGlobal('fetch', fetchMock)

    renderApp('/follow-ups')
    await screen.findByText('جارٍ تحميل المتابعات…')

    fireEvent.change(screen.getByLabelText('الحالة'), { target: { value: 'SUPPRESSED' } })

    await waitFor(() => expect(within(screen.getByRole('table')).getByText('تكليف')).toBeInTheDocument())
    // The filter change already ran the effect cleanup that cancels the old request — this test
    // is about what happens when that request still completes anyway, not whether cancellation
    // was attempted.
    expect(staleRequestAborted).toBe(true)

    // Let the delayed response actually complete and React process its state update.
    await act(async () => {
      stale.resolve?.()
      await Promise.resolve()
    })

    // Re-query the table fresh rather than relying on the reference captured before this update.
    const tableAfterStaleResponse = screen.getByRole('table')
    expect(within(tableAfterStaleResponse).getByText('تكليف')).toBeInTheDocument()
    expect(within(tableAfterStaleResponse).queryByText('انتداب كلي')).not.toBeInTheDocument()
  })
})

describe('Follow-ups page — errors and retry', () => {
  it('shows a retry action on a read failure and refetches on click', async () => {
    let calls = 0
    stubFollowUps({
      principal: principalWith([MOVEMENT_VIEW]),
      movement: () => {
        calls += 1
        return calls === 1 ? jsonResponse({ message: 'Server error.' }, 500) : jsonResponse(page([moveRow()]))
      },
    })
    const user = userEvent.setup()
    renderApp('/follow-ups')

    await screen.findByText('تعذّر تحميل المتابعات.')
    await user.click(screen.getByRole('button', { name: /إعادة المحاولة/ }))

    await screen.findByText('انتداب كلي')
    expect(calls).toBe(2)
  })

  it('shows the unauthorized panel without a retry action on a 403 from the backend', async () => {
    stubFollowUps({ principal: principalWith([MOVEMENT_VIEW]), movement: () => jsonResponse({ message: 'Forbidden.' }, 403) })
    renderApp('/follow-ups')

    await screen.findByText('لا تملك صلاحية الوصول')
    expect(screen.queryByRole('button', { name: /إعادة المحاولة/ })).not.toBeInTheDocument()
  })
})
