import { act, render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it, vi } from 'vitest'
import { I18nProvider } from '../../i18n/I18nProvider'
import { ar } from '../../i18n/messages/ar'
import { HR_PERMISSIONS } from '../../shared/security/permissions'
import { fakeAuthValue, jsonResponse, stubFetch } from '../../test/render'
import { AuthContext } from '../auth/context'
import { PermissionGate } from '../auth/PermissionGate'
import type {
  EvidenceGap,
  PersonQualification,
  PersonQualificationVersion,
  PrimaryQualificationHistoryEvent,
  PrimaryQualificationHistoryPage,
  QualificationPage,
} from './api'
import { Employee360QualificationsSection } from './Employee360QualificationsSection'

/**
 * S49 (docs/person-qualification-history-ui-specification.md). Covers Employee360QualificationsSection
 * together with the two archive panels it mounts internally (QualificationVersionHistoryPanel,
 * PrimaryQualificationHistoryPanel) and QualificationArchivePagination beneath them -- exercised only
 * through Employee360QualificationsSection's own rendered output, never imported/invoked directly,
 * exactly as Employee360CareerHistory.tsx uses them in production.
 *
 * Renders the section directly (not through the whole Employee360 page/router), the same way
 * PermissionGate.test.tsx and SystemStatusCard.test.tsx render one feature component with a fake
 * AuthContext -- this lets every test control pagination/error/timing precisely without paying for
 * the whole page's other sections.
 */

const e = ar.employee360
const sec = ar.securityShared
const sys = ar.systemStatus

const PERSON_ID = 'person-1'
const DEGREE_NAME = 'بكالوريوس العلوم'
const QTYPE_NAME = 'شهادة أكاديمية'
const DEGREE_NAME_2 = 'ماجستير الإدارة'
const QTYPE_NAME_2 = 'دبلوم مهني'

function qualification(overrides: Partial<PersonQualification> = {}): PersonQualification {
  return {
    id: 'q-1',
    person_id: PERSON_ID,
    academic_degree_id: 'deg-1',
    qualification_type_id: 'qt-1',
    obtained_on: '2015-06-01',
    created_at: '2015-06-02T00:00:00Z',
    version_number: 2,
    provenance: 'RECORDED',
    is_primary: true,
    ...overrides,
  }
}

function version(overrides: Partial<PersonQualificationVersion> = {}): PersonQualificationVersion {
  return {
    version_number: 1,
    academic_degree_id: 'deg-1',
    qualification_type_id: 'qt-1',
    obtained_on: '2015-06-01',
    reason: null,
    is_current: false,
    provenance: 'RECORDED',
    created_by_principal_id: 'admin-1',
    created_at: '2015-06-02T00:00:00Z',
    ...overrides,
  }
}

function versionsPage(
  rows: PersonQualificationVersion[],
  meta: Partial<QualificationPage<PersonQualificationVersion>['meta']> = {},
): QualificationPage<PersonQualificationVersion> {
  return {
    data: rows,
    meta: { current_page: 1, last_page: 1, per_page: 25, total: rows.length, ...meta },
  }
}

function designatedEvent(overrides: Partial<PrimaryQualificationHistoryEvent> = {}): PrimaryQualificationHistoryEvent {
  return {
    type: 'DESIGNATED',
    qualification_id: 'q-event-1',
    previous_primary_qualification_id: null,
    actor_principal_id: 'admin-1',
    occurred_at: '2026-01-01T09:00:00Z',
    ...overrides,
  }
}

function primaryHistoryPage(
  events: PrimaryQualificationHistoryEvent[],
  options: { meta?: Partial<PrimaryQualificationHistoryPage['meta']>; gaps?: EvidenceGap[] } = {},
): PrimaryQualificationHistoryPage {
  return {
    events,
    meta: { current_page: 1, last_page: 1, per_page: 25, total: events.length, ...options.meta },
    evidence_completeness: { gaps: options.gaps ?? [] },
  }
}

/** The academic-degree / qualification-type reference lookups every current-list and version row needs resolved. */
function referenceRoute(url: string): Response | undefined {
  if (url.includes('/reference/academic-degrees/deg-1')) {
    return jsonResponse({ id: 'deg-1', code: 'BSC', name_ar: DEGREE_NAME, name_en: 'Bachelor of Science' })
  }
  if (url.includes('/reference/qualification-types/qt-1')) {
    return jsonResponse({ id: 'qt-1', code: 'ACADEMIC', name_ar: QTYPE_NAME, name_en: 'Academic degree' })
  }
  if (url.includes('/reference/academic-degrees/deg-2')) {
    return jsonResponse({ id: 'deg-2', code: 'MSC', name_ar: DEGREE_NAME_2, name_en: 'Master of Business' })
  }
  if (url.includes('/reference/qualification-types/qt-2')) {
    return jsonResponse({ id: 'qt-2', code: 'PROF', name_ar: QTYPE_NAME_2, name_en: 'Professional diploma' })
  }
  return undefined
}

/** Matches QualificationVersionHistoryPanel.tsx's exact aria-label template. */
const versionHistoryButtonName = (label: string) => `${e.versionHistory} — ${label}`

/**
 * Reads the `page` query parameter exactly, never by substring. A plain `url.includes('page=2')`
 * false-positives on `per_page=25` (which contains the literal substring "page=2" followed by "5"),
 * so every mock branch and assertion that needs to tell pages apart uses this instead.
 */
function pageOf(url: string): string | null {
  return new URL(url, 'http://localhost').searchParams.get('page')
}

/** Exactly how Employee360CareerHistory.tsx mounts the section: gated, remounted per personId. */
function Harness({ personId }: { personId: string }) {
  return (
    <PermissionGate permission={HR_PERMISSIONS.personQualificationsView}>
      <Employee360QualificationsSection key={personId} personId={personId} />
    </PermissionGate>
  )
}

function renderWithAuth(permissions: string[], personId = PERSON_ID) {
  return render(
    <I18nProvider>
      <AuthContext.Provider value={fakeAuthValue({ permissions })}>
        <Harness personId={personId} />
      </AuthContext.Provider>
    </I18nProvider>,
  )
}

/** Installs a default-routing fetch mock (current list + empty archives + reference lookups) and renders the section. */
function renderSection(
  options: {
    permissions?: string[]
    currentList?: PersonQualification[]
    overrides?: (url: string) => Response | Promise<Response> | undefined
    personId?: string
  } = {},
) {
  const permissions = options.permissions ?? [HR_PERMISSIONS.personQualificationsView]
  const personId = options.personId ?? PERSON_ID
  const currentList = options.currentList ?? [qualification()]

  const fetchMock = stubFetch((url) => {
    const custom = options.overrides?.(url)
    if (custom) return custom
    const reference = referenceRoute(url)
    if (reference) return reference
    if (/\/qualifications\/[^/]+\/versions(\?|$)/.test(url)) return jsonResponse(versionsPage([]))
    if (url.includes('/qualifications/primary-history')) return jsonResponse(primaryHistoryPage([]))
    if (url.includes(`/hr/persons/${personId}/qualifications`)) return jsonResponse(currentList)
    return jsonResponse({ message: 'Not found.' }, 404)
  })

  const view = renderWithAuth(permissions, personId)
  return { fetchMock, ...view }
}

describe('Employee360QualificationsSection — permission gating', () => {
  it('never issues a qualifications request when the PermissionGate blocks mounting (no hr.person_qualifications.view)', async () => {
    const { fetchMock } = renderSection({ permissions: [] })

    expect(await screen.findByText(sec.unauthorizedTitle)).toBeInTheDocument()
    expect(screen.queryByText(e.qualifications)).not.toBeInTheDocument()

    const urls = fetchMock.mock.calls.map((call) => String(call[0]))
    expect(urls.some((url) => url.includes('/qualifications'))).toBe(false)
  })
})

describe('Employee360QualificationsSection — current list', () => {
  it('shows the resolved degree/type names, the obtained date, the primary flag and the provenance label from real data', async () => {
    renderSection({ currentList: [qualification()] })

    expect(await screen.findByText(DEGREE_NAME)).toBeInTheDocument()
    expect(screen.getByText(QTYPE_NAME)).toBeInTheDocument()
    expect(screen.getByText('01/06/2015')).toBeInTheDocument()
    expect(screen.getByText(e.yes)).toBeInTheDocument()
    expect(screen.getByText(e.provenanceRecorded)).toBeInTheDocument()
  })

  it('shows the empty-state copy when the person has no recorded qualifications', async () => {
    renderSection({ currentList: [] })

    expect(await screen.findByText(e.noQualifications)).toBeInTheDocument()
  })
})

describe('Employee360QualificationsSection — lazy archive fetching', () => {
  it('never requests version history or primary history before their panel is opened', async () => {
    const { fetchMock } = renderSection({ currentList: [qualification()] })
    await screen.findByText(DEGREE_NAME)

    const urls = fetchMock.mock.calls.map((call) => String(call[0]))
    expect(urls.some((url) => url.includes('/versions'))).toBe(false)
    expect(urls.some((url) => url.includes('/primary-history'))).toBe(false)
  })
})

describe('Employee360QualificationsSection — version history: date vs actor are independent', () => {
  it('shows "date unknown" while still showing the known actor for a RECORDED version with obtained_on=null, and never infers the date from created_at', async () => {
    const user = userEvent.setup()
    const theVersion = version({
      version_number: 1,
      obtained_on: null,
      provenance: 'RECORDED',
      created_by_principal_id: 'admin-7',
      created_at: '2015-06-02T00:00:00Z',
    })
    renderSection({
      overrides: (url) => (/\/versions(\?|$)/.test(url) ? jsonResponse(versionsPage([theVersion])) : undefined),
    })

    await user.click(await screen.findByRole('button', { name: versionHistoryButtonName(DEGREE_NAME) }))

    expect(await screen.findByText(e.obtainedOnUnknown)).toBeInTheDocument()
    expect(screen.getByText('admin-7')).toBeInTheDocument()
    expect(screen.queryByText(e.actorUnknown)).not.toBeInTheDocument()
    // created_at's own date must never leak through as if it were the obtained-on date.
    expect(screen.queryByText('02/06/2015')).not.toBeInTheDocument()
  })

  it('shows "actor unknown" for a BACKFILLED_UNKNOWN_ACTOR version while its real obtained date is still shown', async () => {
    const user = userEvent.setup()
    const theVersion = version({
      version_number: 2,
      obtained_on: '2016-03-10',
      provenance: 'BACKFILLED_UNKNOWN_ACTOR',
      created_by_principal_id: null,
      is_current: true,
    })
    renderSection({
      overrides: (url) => (/\/versions(\?|$)/.test(url) ? jsonResponse(versionsPage([theVersion])) : undefined),
    })

    await user.click(await screen.findByRole('button', { name: versionHistoryButtonName(DEGREE_NAME) }))

    expect(await screen.findByText(e.actorUnknown)).toBeInTheDocument()
    expect(screen.getByText('10/03/2016')).toBeInTheDocument()
    expect(screen.queryByText(e.obtainedOnUnknown)).not.toBeInTheDocument()
  })
})

describe('Employee360QualificationsSection — primary history: ordering is never re-sorted', () => {
  it('renders primary-designation events in exactly the order the backend returned them, even when that order is not chronological', async () => {
    const user = userEvent.setup()
    const EVENTS: PrimaryQualificationHistoryEvent[] = [
      designatedEvent({ qualification_id: 'q-later', occurred_at: '2026-05-01T08:00:00Z', type: 'DESIGNATED' }),
      designatedEvent({ qualification_id: 'q-earlier', occurred_at: '2020-01-01T08:00:00Z', type: 'AUTO_FIRST' }),
    ]
    renderSection({
      overrides: (url) => (url.includes('/primary-history') ? jsonResponse(primaryHistoryPage(EVENTS)) : undefined),
    })

    await user.click(await screen.findByRole('button', { name: e.primaryHistory }))
    await screen.findByText('q-later')

    const table = screen.getByRole('table')
    const bodyRows = within(table)
      .getAllByRole('row')
      .filter((row) => within(row).queryAllByRole('cell').length > 0)
    const referenceCellTexts = bodyRows.map((row) => within(row).getAllByRole('cell')[1]?.textContent ?? '')
    // Column order: event type, qualification reference, previous primary, actor, occurred-at --
    // index 1 is the qualification reference, read in fixture-array order, never re-sorted by date.
    expect(referenceCellTexts).toEqual(['q-later', 'q-earlier'])
  })
})

describe('Employee360QualificationsSection — primary history: evidence gaps', () => {
  it('shows the identical evidence gap on every page, never as a row inside the events table', async () => {
    const user = userEvent.setup()
    const GAPS: EvidenceGap[] = [{ code: 'GAP_CHAIN_BROKEN', qualification_id: 'q-broken-link' }]
    renderSection({
      overrides: (url) => {
        if (!url.includes('/primary-history')) return undefined
        if (pageOf(url) === '2') {
          return jsonResponse(
            primaryHistoryPage([designatedEvent({ qualification_id: 'q-page-2' })], {
              meta: { current_page: 2, last_page: 2, total: 2 },
              gaps: GAPS,
            }),
          )
        }
        return jsonResponse(
          primaryHistoryPage([designatedEvent({ qualification_id: 'q-page-1' })], {
            meta: { current_page: 1, last_page: 2, total: 2 },
            gaps: GAPS,
          }),
        )
      },
    })

    await user.click(await screen.findByRole('button', { name: e.primaryHistory }))
    await screen.findByText('q-page-1')

    expect(screen.getByText(e.gapChainBroken, { exact: false })).toBeInTheDocument()
    expect(screen.getByText('q-broken-link')).toBeInTheDocument()
    expect(within(screen.getByRole('table')).queryByText('q-broken-link')).not.toBeInTheDocument()

    await user.click(screen.getByRole('button', { name: e.nextPage }))
    await screen.findByText('q-page-2')

    expect(screen.getByText(e.gapChainBroken, { exact: false })).toBeInTheDocument()
    expect(screen.getByText('q-broken-link')).toBeInTheDocument()
    expect(within(screen.getByRole('table')).queryByText('q-broken-link')).not.toBeInTheDocument()
  })

  it('shows no evidence-gaps block when the primary-designation history is empty', async () => {
    renderSection({
      overrides: (url) => (url.includes('/primary-history') ? jsonResponse(primaryHistoryPage([])) : undefined),
    })
    const user = userEvent.setup()

    await user.click(await screen.findByRole('button', { name: e.primaryHistory }))

    expect(await screen.findByText(e.noPrimaryHistory)).toBeInTheDocument()
    expect(screen.queryByText(e.evidenceGapsTitle)).not.toBeInTheDocument()
  })

  it('shows the empty state together with both evidence-gap codes when events is empty but gaps are not', async () => {
    const GAPS: EvidenceGap[] = [
      { code: 'GAP_NO_DESIGNATION_EVIDENCE', qualification_id: 'q-no-evidence' },
      { code: 'GAP_CHAIN_BROKEN', qualification_id: 'q-broken-link' },
    ]
    renderSection({
      overrides: (url) =>
        url.includes('/primary-history') ? jsonResponse(primaryHistoryPage([], { gaps: GAPS })) : undefined,
    })
    const user = userEvent.setup()

    await user.click(await screen.findByRole('button', { name: e.primaryHistory }))

    expect(await screen.findByText(e.noPrimaryHistory)).toBeInTheDocument()
    expect(screen.getByText(e.evidenceGapsTitle)).toBeInTheDocument()
    expect(screen.getByText(e.gapNoDesignationEvidence, { exact: false })).toBeInTheDocument()
    expect(screen.getByText(e.gapChainBroken, { exact: false })).toBeInTheDocument()
    expect(screen.getByText('q-no-evidence')).toBeInTheDocument()
    expect(screen.getByText('q-broken-link')).toBeInTheDocument()
    expect(screen.queryByRole('table')).not.toBeInTheDocument()
  })
})

describe('Employee360QualificationsSection — pagination resets on reopen', () => {
  it('resets the version-history panel back to page 1 every time it is reopened, never continuing from where it was left', async () => {
    const user = userEvent.setup()
    const V1 = version({ version_number: 1, reason: null })
    const V2 = version({ version_number: 2, is_current: true, reason: 'MARKER-V2' })
    const { fetchMock } = renderSection({
      overrides: (url) => {
        if (!/\/versions(\?|$)/.test(url)) return undefined
        if (pageOf(url) === '2') return jsonResponse(versionsPage([V2], { current_page: 2, last_page: 2, total: 2 }))
        return jsonResponse(versionsPage([V1], { current_page: 1, last_page: 2, total: 2 }))
      },
    })

    const openButton = () => screen.getByRole('button', { name: versionHistoryButtonName(DEGREE_NAME) })

    await user.click(await screen.findByRole('button', { name: versionHistoryButtonName(DEGREE_NAME) }))
    await waitFor(() => expect(fetchMock.mock.calls.some((call) => pageOf(String(call[0])) === '1')).toBe(true))

    await user.click(await screen.findByRole('button', { name: e.nextPage }))
    await screen.findByText('MARKER-V2')

    await user.click(screen.getByRole('button', { name: ar.app.close }))
    await waitFor(() => expect(screen.queryByText('MARKER-V2')).not.toBeInTheDocument())

    const callsBeforeReopen = fetchMock.mock.calls.length
    await user.click(openButton())

    await waitFor(() => expect(fetchMock.mock.calls.length).toBeGreaterThan(callsBeforeReopen))
    const firstNewCall = String(fetchMock.mock.calls[callsBeforeReopen]?.[0] ?? '')
    expect(firstNewCall).toContain('/versions')
    // One exact equality proves both what the old substring checks intended: this request is for
    // page 1 (and, being exactly '1', therefore necessarily not page 2 or any other page) --
    // `.toContain('page=1')` / `.not.toContain('page=2')` are not used here because `per_page=25`
    // contains the literal substring "page=2", which made `.not.toContain('page=2')` fail even on
    // a genuine page=1 request.
    expect(pageOf(firstNewCall)).toBe('1')
  })
})

describe('Employee360QualificationsSection — person switch leaves no stale data', () => {
  it('shows no stale data from a previously-viewed person immediately after personId changes (key={personId} remount, mirroring Employee360CareerHistory.tsx)', async () => {
    const fetchMock = vi.fn((input: RequestInfo | URL) => {
      const url = String(input)
      const reference = referenceRoute(url)
      if (reference) return Promise.resolve(reference)
      if (/\/versions(\?|$)/.test(url)) return Promise.resolve(jsonResponse(versionsPage([])))
      if (url.includes('/primary-history')) return Promise.resolve(jsonResponse(primaryHistoryPage([])))
      if (url.includes('/hr/persons/person-1/qualifications')) {
        return Promise.resolve(jsonResponse([qualification({ id: 'q-person-1' })]))
      }
      if (url.includes('/hr/persons/person-2/qualifications')) {
        return Promise.resolve(
          jsonResponse([
            qualification({ id: 'q-person-2', academic_degree_id: 'deg-2', qualification_type_id: 'qt-2', is_primary: false }),
          ]),
        )
      }
      return Promise.resolve(jsonResponse({ message: 'Not found.' }, 404))
    })
    vi.stubGlobal('fetch', fetchMock)

    function Wrapper({ personId }: { personId: string }) {
      return (
        <I18nProvider>
          <AuthContext.Provider value={fakeAuthValue({ permissions: [HR_PERMISSIONS.personQualificationsView] })}>
            <Harness personId={personId} />
          </AuthContext.Provider>
        </I18nProvider>
      )
    }

    const { rerender } = render(<Wrapper personId="person-1" />)
    expect(await screen.findByText(DEGREE_NAME)).toBeInTheDocument()

    rerender(<Wrapper personId="person-2" />)
    // Immediately after the key change forces a remount, person-1's resolved degree must be gone --
    // never shown even momentarily against person-2's data.
    expect(screen.queryByText(DEGREE_NAME)).not.toBeInTheDocument()
    expect(await screen.findByText(DEGREE_NAME_2)).toBeInTheDocument()
  })
})

describe('Employee360QualificationsSection — no stale panel data survives a close/reopen', () => {
  it(
    "never shows a previously-open version-history request's late, real-data response after the panel " +
      'was closed and reopened with a newer request already rendered',
    async () => {
      // REFRAMED after independent review (see final delivery report): this test does NOT, on its
      // own, prove useApiResource's `if (!controller.signal.aborted)` guard -- closing the Sheet
      // fully unmounts QualificationVersionHistoryContent, so the stale request's late setState
      // call lands on an already-unmounted instance and is a React no-op for that reason alone,
      // regardless of whether the abort-guard exists. That general-purpose, same-mounted-instance
      // guarantee (a request superseded by a `deps` change, NOT an unmount, must never let its late
      // response overwrite the newer one) is proven directly and unambiguously at the hook level in
      // the new, dedicated frontend/src/shared/hooks/useApiResource.test.ts -- the one place in this
      // codebase that guarantee is actually general-purpose. useApiResource.ts itself is untouched;
      // only that new test file was added, mirroring FollowUpsPage.test.tsx's own
      // "discards a stale response that resolves WITH data after a newer one" technique exactly, at
      // the hook level instead of through a page's live filter control.
      //
      // What THIS test still proves, and is a real, valuable, UI-level regression guard in its own
      // right: a user who closes the version-history panel before it loads and reopens it never
      // sees the first (now-abandoned) request's data bleed into the reopened view, even if that
      // first request's response arrives late. An independent S49 review confirmed no other live,
      // same-mounted-instance opportunity exists anywhere in these panels to reproduce the abort-
      // guard race through real UI interaction alone: every identity change here (closing a Sheet,
      // switching the viewed person) is mediated by a full unmount, and a repeated click within one
      // still-mounted panel cannot target a different page before its in-flight request resolves
      // (QualificationArchivePagination's Prev/Next targets are computed from the last-rendered,
      // not-yet-updated `meta`, so a second click before resolution recomputes the SAME target page).
      const stale: { resolve: (() => void) | null } = { resolve: null }
      let staleRequestAborted = false
      let firstOpenPage1Calls = 0

      const STALE_FIRST_OPEN_VERSION = version({ version_number: 1, reason: 'MARKER-STALE-FIRST-OPEN' })
      const FRESH_REOPEN_VERSION = version({ version_number: 1, reason: 'MARKER-FRESH-REOPEN' })

      const fetchMock = vi.fn((input: RequestInfo | URL, init?: RequestInit) => {
        const url = String(input)
        const reference = referenceRoute(url)
        if (reference) return Promise.resolve(reference)
        if (url.includes(`/hr/persons/${PERSON_ID}/qualifications`) && !url.includes('/versions')) {
          return Promise.resolve(jsonResponse([qualification()]))
        }
        if (/\/versions(\?|$)/.test(url) && pageOf(url) === '1') {
          firstOpenPage1Calls += 1
          if (firstOpenPage1Calls === 1) {
            // The FIRST open's page-1 request: held pending, and deliberately does NOT reject on
            // abort -- it stays unresolved until resolveStale() is called below, well after the
            // reopened panel's own fresh page-1 request has already rendered.
            return new Promise<Response>((resolve) => {
              stale.resolve = () => resolve(jsonResponse(versionsPage([STALE_FIRST_OPEN_VERSION])))
              init?.signal?.addEventListener('abort', () => {
                staleRequestAborted = true
              })
            })
          }
          // The REOPENED panel's own fresh page-1 request: resolves normally and quickly.
          return Promise.resolve(jsonResponse(versionsPage([FRESH_REOPEN_VERSION])))
        }
        return Promise.resolve(jsonResponse({ message: 'Not found.' }, 404))
      })
      vi.stubGlobal('fetch', fetchMock)

      const user = userEvent.setup()
      render(
        <I18nProvider>
          <AuthContext.Provider value={fakeAuthValue({ permissions: [HR_PERMISSIONS.personQualificationsView] })}>
            <Harness personId={PERSON_ID} />
          </AuthContext.Provider>
        </I18nProvider>,
      )

      await screen.findByText(DEGREE_NAME)
      // Open the panel: its page-1 request fires and stays pending (the "stale" one).
      await user.click(screen.getByRole('button', { name: versionHistoryButtonName(DEGREE_NAME) }))
      await waitFor(() => expect(firstOpenPage1Calls).toBe(1))

      // Close the panel while that request is still unresolved. QualificationVersionHistoryContent
      // unmounts, running its effect cleanup, which aborts the pending request.
      await user.click(screen.getByRole('button', { name: ar.app.close }))
      await waitFor(() => expect(staleRequestAborted).toBe(true))

      // Reopen: a brand-new page-1 request fires and resolves quickly with different, identifiable
      // data, which renders.
      await user.click(screen.getByRole('button', { name: versionHistoryButtonName(DEGREE_NAME) }))
      expect(await screen.findByText('MARKER-FRESH-REOPEN')).toBeInTheDocument()

      // ONLY THEN does the original (first-open) stale request resolve, late, with real data of its
      // own. It must never be allowed to overwrite the already-rendered newer content.
      await act(async () => {
        stale.resolve?.()
        await Promise.resolve()
      })

      expect(screen.getByText('MARKER-FRESH-REOPEN')).toBeInTheDocument()
      expect(screen.queryByText('MARKER-STALE-FIRST-OPEN')).not.toBeInTheDocument()
    },
  )
})

describe('Employee360QualificationsSection — version history: 403 vs other failure', () => {
  it('shows the unauthorized panel with no retry button on a 403 from GET .../versions', async () => {
    renderSection({
      overrides: (url) => (/\/versions(\?|$)/.test(url) ? jsonResponse({ message: 'Forbidden.' }, 403) : undefined),
    })
    const user = userEvent.setup()

    await user.click(await screen.findByRole('button', { name: versionHistoryButtonName(DEGREE_NAME) }))

    expect(await screen.findByText(sec.unauthorizedTitle)).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: sys.retry })).not.toBeInTheDocument()
  })

  it('shows a retry action on a 500 from GET .../versions, and clicking it re-issues exactly one new request', async () => {
    let versionCalls = 0
    const FIXED = version({ reason: 'MARKER-AFTER-RETRY' })
    const { fetchMock } = renderSection({
      overrides: (url) => {
        if (!/\/versions(\?|$)/.test(url)) return undefined
        versionCalls += 1
        return versionCalls === 1 ? jsonResponse({ message: 'Server error.' }, 500) : jsonResponse(versionsPage([FIXED]))
      },
    })
    const user = userEvent.setup()

    await user.click(await screen.findByRole('button', { name: versionHistoryButtonName(DEGREE_NAME) }))
    await screen.findByText(e.loadFailed)

    const callsBeforeRetry = fetchMock.mock.calls.filter((call) => String(call[0]).includes('/versions')).length
    await user.click(screen.getByRole('button', { name: sys.retry }))

    expect(await screen.findByText('MARKER-AFTER-RETRY')).toBeInTheDocument()
    const callsAfterRetry = fetchMock.mock.calls.filter((call) => String(call[0]).includes('/versions')).length
    expect(callsAfterRetry - callsBeforeRetry).toBe(1)
    expect(versionCalls).toBe(2)
  })
})

describe('Employee360QualificationsSection — read-only guarantee', () => {
  it('never issues a non-GET request to any qualifications URL across list/version-history/primary-history interactions', async () => {
    const user = userEvent.setup()
    const V1 = version({ version_number: 1 })
    const { fetchMock } = renderSection({
      overrides: (url) => {
        if (/\/versions(\?|$)/.test(url)) return jsonResponse(versionsPage([V1]))
        if (url.includes('/primary-history')) return jsonResponse(primaryHistoryPage([designatedEvent()]))
        return undefined
      },
    })

    await user.click(await screen.findByRole('button', { name: versionHistoryButtonName(DEGREE_NAME) }))
    await screen.findByText(e.versionHistoryTitle)
    await user.click(screen.getByRole('button', { name: ar.app.close }))
    // Wait for the version-history Sheet to fully close (Radix unmounts a closed Dialog.Content)
    // before opening the primary-history Sheet, so the two Sheets' dialogs never momentarily coexist.
    await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument())

    await user.click(await screen.findByRole('button', { name: e.primaryHistory }))
    // e.primaryHistory (the trigger button's own label) and e.primaryHistoryTitle (this Sheet's
    // title) are the identical Arabic string by design (ar.ts) -- screen.findByText(e.primaryHistoryTitle)
    // would match BOTH the still-mounted trigger button and the Sheet's own title and throw on
    // ambiguity. Scoping to the open dialog, then to its heading role, resolves it unambiguously
    // (SheetTitle renders Radix's Dialog.Title, which is a real <h2>, i.e. role="heading").
    const primaryDialog = await screen.findByRole('dialog')
    expect(within(primaryDialog).getByRole('heading', { name: e.primaryHistoryTitle })).toBeInTheDocument()

    // Prove both history requests actually fired before asserting anything about their HTTP method --
    // a vacuous pass (zero matching calls) must never be mistaken for a genuine read-only guarantee.
    const requestedUrls = fetchMock.mock.calls.map((call) => String(call[0]))
    expect(requestedUrls.some((url) => /\/versions(\?|$)/.test(url))).toBe(true)
    expect(requestedUrls.some((url) => url.includes('/primary-history'))).toBe(true)

    const qualificationCalls = fetchMock.mock.calls.filter((call) => String(call[0]).includes('qualification'))
    expect(qualificationCalls.length).toBeGreaterThan(0)
    for (const call of qualificationCalls) {
      // stubFetch's handler type only declares one parameter, so TypeScript infers a length-1
      // tuple for vi.fn's recorded call args even though fetch (and this mock) is always actually
      // invoked with two -- widen to a plain array before indexing the (real, present) second arg.
      const init = (call as unknown[])[1] as RequestInit | undefined
      expect(init?.method ?? 'GET').toBe('GET')
    }
  })
})
