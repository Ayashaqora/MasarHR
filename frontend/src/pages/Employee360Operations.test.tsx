import { fireEvent, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it } from 'vitest'
import { ar } from '../i18n/messages/ar'
import { en } from '../i18n/messages/en'
import { install360, type HarnessOptions } from '../test/employee360Fixtures'
import { jsonResponse, renderApp } from '../test/render'

const ROUTE = '/employees/person-1/relationships/rel-1'
const o = ar.operations
type User = ReturnType<typeof userEvent.setup>

/** The first render also pays the cold import of the lazy route chunk, hence the explicit bound. */
async function renderPage(options: HarnessOptions = {}, locale: 'ar' | 'en' = 'ar') {
  const harness = install360(options)
  const user = userEvent.setup()
  renderApp(ROUTE, locale)
  const title = locale === 'ar' ? ar.employee360.title : en.employee360.title
  await screen.findByRole('heading', { level: 1, name: title }, { timeout: 4000 })
  return { harness, user }
}

async function openTab(user: User, name: string) {
  await user.click(await screen.findByRole('tab', { name }))
}

async function openAction(user: User, name: string) {
  await user.click(await screen.findByRole('button', { name }))
  return within(await screen.findByRole('dialog'))
}

function setDate(dialog: ReturnType<typeof within>, label: string, value: string) {
  fireEvent.change(dialog.getByLabelText(label), { target: { value } })
}

/**
 * The visible day/month/year segment for a `DateInput` field — as opposed to `setDate`'s hidden native
 * `<input type="date">`, which proves only that the hidden input works, not the actual rendered UI (dd/MM/yyyy
 * segments, aria wiring, validity). Use these for anything checking what a real user sees and types.
 */
function dateSegment(dialog: ReturnType<typeof within>, label: string, part: 'day' | 'month' | 'year') {
  const partLabel = part === 'day' ? ar.dateInput.day : part === 'month' ? ar.dateInput.month : ar.dateInput.year
  return dialog.getByLabelText(`${label} — ${partLabel}`)
}

/** Types day/month/year into the visible segments (not the hidden native input), via the real keyboard interaction. */
async function typeVisibleDate(user: User, dialog: ReturnType<typeof within>, label: string, day: string, month: string, year: string) {
  await user.type(dateSegment(dialog, label, 'day'), day)
  await user.type(dateSegment(dialog, label, 'month'), month)
  await user.type(dateSegment(dialog, label, 'year'), year)
}

async function pickOption(user: User, dialog: ReturnType<typeof within>, label: string, optionName: string, value: string) {
  await dialog.findByRole('option', { name: optionName })
  await user.selectOptions(dialog.getByLabelText(label), value)
}

const MOVEMENT = ar.employee360.tabMovementTimeline
const SCHEDULE = ar.employee360.tabWorkArrangements
const EMPLOYMENT = ar.employee360.tabEmployment
const STATUS_URL = '/hr/persons/person-1/employment-relationships/rel-1/status-periods'
const BASE = '/hr/persons/person-1/employment-relationships/rel-1'

describe('Employee 360 operations — permissions (read never implies write)', () => {
  it('offers no write action to a caller with read permissions only, while every read stays available', async () => {
    await renderPage()
    expect(document.getElementById('status-history-heading')).not.toBeNull()
    for (const name of [o.recordStatus, o.recordIntention]) {
      expect(screen.queryByRole('button', { name })).not.toBeInTheDocument()
    }
    for (const name of [o.transfer, o.startSecondment, o.endSecondment, o.startAssignment, o.endAssignment, o.recordPartial, o.recordSchedule, o.endRelationship]) {
      expect(screen.queryByRole('button', { name, hidden: true })).not.toBeInTheDocument()
    }
  })

  it.each([
    ['hr.employment_status_periods.record', null, o.recordStatus],
    ['hr.return_intention_periods.record', null, o.recordIntention],
    ['hr.employment_relationships.transfer', MOVEMENT, o.transfer],
    ['hr.full_secondment_periods.start', MOVEMENT, o.startSecondment],
    ['hr.workplace_assignment_periods.start', MOVEMENT, o.startAssignment],
    ['hr.partial_secondment_periods.record', MOVEMENT, o.recordPartial],
    ['hr.work_schedule_periods.record', SCHEDULE, o.recordSchedule],
    ['hr.employment_relationships.end', EMPLOYMENT, o.endRelationship],
  ])('shows the action guarded by %s only with that permission', async (permission, tab, name) => {
    const { user } = await renderPage({ permissions: [permission] })
    if (tab) await openTab(user, tab)
    expect(await screen.findByRole('button', { name })).toBeEnabled()
  })

  it('keeps End Secondment / End Assignment disabled until the read shows an open period', async () => {
    const { user } = await renderPage({ permissions: ['hr.full_secondment_periods.end', 'hr.workplace_assignment_periods.end'] })
    await openTab(user, MOVEMENT)
    expect(await screen.findByRole('button', { name: o.endSecondment })).toBeDisabled()
    expect(screen.getByRole('button', { name: o.endAssignment })).toBeDisabled()
  })

  it('enables End Secondment when an open secondment exists', async () => {
    const { user } = await renderPage({
      permissions: ['hr.full_secondment_periods.end'],
      secondments: [{ id: 'fs-1', employment_relationship_id: 'rel-1', organizational_unit_id: 'unit-9', effective_from: '2026-01-01', effective_to: null }],
    })
    await openTab(user, MOVEMENT)
    expect(await screen.findByRole('button', { name: o.endSecondment })).toBeEnabled()
  })

  it('offers no operation on an ended relationship and says so', async () => {
    const { user } = await renderPage({
      permissions: ['hr.employment_status_periods.record', 'hr.employment_relationships.transfer', 'hr.employment_relationships.end'],
      relationship: { end_knowledge_state: 'KNOWN', effective_to: '2025-01-01', ended_terminally: false },
    })
    expect(screen.queryByRole('button', { name: o.recordStatus })).not.toBeInTheDocument()
    expect(screen.getAllByText(o.relationshipEndedNote).length).toBeGreaterThan(0)
    await openTab(user, MOVEMENT)
    expect(screen.queryByRole('button', { name: o.transfer })).not.toBeInTheDocument()
  })
})

describe('Employee 360 operations — employment status', () => {
  const STATUS = { permissions: ['hr.employment_status_periods.record'] }

  it('posts the exact contract payload, refetches effective status + history, and announces success', async () => {
    const { harness, user } = await renderPage(STATUS)
    const dialog = await openAction(user, o.recordStatus)
    await user.selectOptions(dialog.getByLabelText(o.statusLabel), 'traveling')
    setDate(dialog, o.effectiveFrom, '2026-10-20')
    await user.selectOptions(dialog.getByLabelText(o.travelPayLabel), 'PAID')
    const before = { effective: harness.getCount('/effective-status'), history: harness.getCount('/rel-1/status-periods') }

    await user.click(dialog.getByRole('button', { name: o.recordStatusSubmit }))

    await waitFor(() => expect(harness.posts).toHaveLength(1))
    expect(harness.posts[0]?.url).toContain(STATUS_URL)
    expect(harness.posts[0]?.body).toEqual({ status_detail_code: 'traveling', effective_from: '2026-10-20', travel_pay_status: 'PAID' })
    expect(await screen.findByText(o.statusSuccess)).toBeInTheDocument()
    await waitFor(() => {
      expect(harness.getCount('/effective-status')).toBeGreaterThan(before.effective)
      expect(harness.getCount('/rel-1/status-periods')).toBeGreaterThan(before.history)
    })
    await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument())
  })

  it('offers an end date only for bounded statuses (required for unpaid leave) and travel pay only for traveling', async () => {
    const { user } = await renderPage(STATUS)
    const dialog = await openAction(user, o.recordStatus)
    expect(dialog.queryByLabelText(o.effectiveTo)).not.toBeInTheDocument()
    await user.selectOptions(dialog.getByLabelText(o.statusLabel), 'unpaid_leave')
    expect(dialog.getByLabelText(o.effectiveTo)).toBeInTheDocument()
    expect(dialog.queryByLabelText(o.travelPayLabel)).not.toBeInTheDocument()
    await user.selectOptions(dialog.getByLabelText(o.statusLabel), 'traveling')
    expect(dialog.getByLabelText(o.travelPayLabel)).toBeInTheDocument()
  })

  it('never offers the retired return-intention codes as statuses and has no end-status action', async () => {
    const { user } = await renderPage(STATUS)
    const dialog = await openAction(user, o.recordStatus)
    await dialog.findByRole('option', { name: 'مسافر' })
    expect(dialog.queryByRole('option', { name: 'يرغب بالعودة (قديم)' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /إنهاء الحالة/ })).not.toBeInTheDocument()
  })

  it('validates required fields client-side without calling the server', async () => {
    const { harness, user } = await renderPage(STATUS)
    const dialog = await openAction(user, o.recordStatus)
    await user.click(dialog.getByRole('button', { name: o.recordStatusSubmit }))
    expect(await dialog.findAllByText(o.required)).toHaveLength(2)
    expect(harness.posts).toHaveLength(0)
  })

  it('requires the end date for a required-end status', async () => {
    const { harness, user } = await renderPage(STATUS)
    const dialog = await openAction(user, o.recordStatus)
    await user.selectOptions(dialog.getByLabelText(o.statusLabel), 'unpaid_leave')
    setDate(dialog, o.effectiveFrom, '2026-10-20')
    await user.click(dialog.getByRole('button', { name: o.recordStatusSubmit }))
    expect(await dialog.findByText(o.required)).toBeInTheDocument()
    expect(harness.posts).toHaveLength(0)
  })

  it('shows the server field message on a 422 and keeps the sheet and the entered values', async () => {
    const { harness, user } = await renderPage({
      ...STATUS,
      onPost: () => jsonResponse({ message: 'Invalid.', errors: { effective_from: ['The start date must be after the relationship start.'] } }, 422),
    })
    const dialog = await openAction(user, o.recordStatus)
    await user.selectOptions(dialog.getByLabelText(o.statusLabel), 'on_duty')
    setDate(dialog, o.effectiveFrom, '2019-01-01')
    await user.click(dialog.getByRole('button', { name: o.recordStatusSubmit }))
    expect(await dialog.findByText('The start date must be after the relationship start.')).toBeInTheDocument()
    expect(dialog.getByText(o.errorValidation)).toBeInTheDocument()
    expect(dialog.getByLabelText(o.effectiveFrom)).toHaveValue('2019-01-01')
    expect(harness.posts).toHaveLength(1)
  })

  it('treats a relationship-ending status as a reviewed ending event and re-reads the relationship', async () => {
    const { harness, user } = await renderPage(STATUS)
    const dialog = await openAction(user, o.recordStatus)
    await user.selectOptions(dialog.getByLabelText(o.statusLabel), 'retired')
    setDate(dialog, o.effectiveFrom, '2026-10-20')
    await user.click(dialog.getByRole('button', { name: o.review }))
    expect(dialog.getAllByText(o.terminalStatusWarning).length).toBeGreaterThan(0)
    expect(harness.posts).toHaveLength(0)
    const relationshipReads = harness.gets.filter((url) => url.endsWith('/employment-relationships')).length
    await user.click(dialog.getByRole('button', { name: o.recordStatusSubmit }))
    await waitFor(() => expect(harness.posts).toHaveLength(1))
    expect(harness.posts[0]?.body).toEqual({ status_detail_code: 'retired', effective_from: '2026-10-20' })
    await waitFor(() => expect(harness.gets.filter((url) => url.endsWith('/employment-relationships')).length).toBeGreaterThan(relationshipReads))
  })

  it('renders travel_pay_status from the API in the status history', async () => {
    await renderPage({
      statusPeriods: [
        { id: 'sp-2', employment_relationship_id: 'rel-1', status_detail_id: 'sd-3', effective_from: '2026-05-01', effective_to: '2026-06-01', travel_pay_status: 'PAID' },
        { id: 'sp-1', employment_relationship_id: 'rel-1', status_detail_id: 'sd-1', effective_from: '2020-01-01', effective_to: null, travel_pay_status: null },
      ],
    })
    expect(await screen.findByText((_, element) => element?.tagName === 'SPAN' && element.textContent === `${o.travelPayLabel}: ${o.travelPayPaid}`)).toBeInTheDocument()
  })
})

describe('Employee 360 operations — return intention (independent of status)', () => {
  it('posts its own contract, in its own form, and refetches the intention reads', async () => {
    const { harness, user } = await renderPage({ permissions: ['hr.return_intention_periods.record'] })
    const dialog = await openAction(user, o.recordIntention)
    expect(dialog.queryByLabelText(o.statusLabel)).not.toBeInTheDocument()
    await user.click(dialog.getByRole('radio', { name: ar.employee360.wantsToReturn }))
    setDate(dialog, o.effectiveFrom, '2026-10-20')
    const before = harness.getCount('/return-intention')
    await user.click(dialog.getByRole('button', { name: o.recordIntentionSubmit }))
    await waitFor(() => expect(harness.posts).toHaveLength(1))
    expect(harness.posts[0]?.url).toContain(`${BASE}/return-intention-periods`)
    expect(harness.posts[0]?.body).toEqual({ intention: 'WANTS_TO_RETURN', effective_from: '2026-10-20' })
    expect(await screen.findByText(o.intentionSuccess)).toBeInTheDocument()
    await waitFor(() => expect(harness.getCount('/return-intention')).toBeGreaterThan(before))
    expect(harness.posts.every((post) => !post.url.includes('/status-periods'))).toBe(true)
  })

  it('requires an intention and a start date', async () => {
    const { harness, user } = await renderPage({ permissions: ['hr.return_intention_periods.record'] })
    const dialog = await openAction(user, o.recordIntention)
    await user.click(dialog.getByRole('button', { name: o.recordIntentionSubmit }))
    expect(await dialog.findAllByText(o.required)).toHaveLength(2)
    expect(harness.posts).toHaveLength(0)
  })
})

/**
 * S46 UI-DATE-001 code review (archive fix): a `DateInput` that cannot form a valid date collapses to an empty
 * `value`, exactly like "left blank on purpose" — so an optional field must still block submission while
 * something invalid is typed, otherwise the invalid entry is silently dropped from the payload. These tests
 * drive the VISIBLE day/month/year segments (never the hidden native input `setDate` uses elsewhere in this
 * file), because only that proves the real rendered UI — not just the hidden input's own contract — blocks.
 */
describe('Employee 360 operations — an invalid optional date must block, not silently drop', () => {
  it('blocks submit when the optional end date is invalid, even though the required start date is valid, and never calls the API', async () => {
    const { harness, user } = await renderPage({ permissions: ['hr.return_intention_periods.record'] })
    const dialog = await openAction(user, o.recordIntention)
    await user.click(dialog.getByRole('radio', { name: ar.employee360.wantsToReturn }))
    await typeVisibleDate(user, dialog, o.effectiveFrom, '20', '10', '2026')
    // 31 April does not exist: non-empty segments that can never form a valid date.
    await typeVisibleDate(user, dialog, o.effectiveTo, '31', '04', '2026')
    expect(await dialog.findByText(ar.dateInput.invalidDate)).toBeInTheDocument()

    await user.click(dialog.getByRole('button', { name: o.recordIntentionSubmit }))

    expect(harness.posts).toHaveLength(0)
    expect(screen.getByRole('dialog')).toBeInTheDocument()
    // The old ISO value carried in state must never reach the API: it must not still be sitting in the hidden
    // input either, since the component never committed the invalid segments onto it.
    expect(dialog.getByLabelText(o.effectiveTo)).toHaveValue('')
  })

  it('blocks submit after a previously-valid optional end date is edited down to an incomplete value', async () => {
    const { harness, user } = await renderPage({ permissions: ['hr.return_intention_periods.record'] })
    const dialog = await openAction(user, o.recordIntention)
    await user.click(dialog.getByRole('radio', { name: ar.employee360.wantsToReturn }))
    await typeVisibleDate(user, dialog, o.effectiveFrom, '20', '10', '2026')
    await typeVisibleDate(user, dialog, o.effectiveTo, '01', '12', '2026')
    expect(dialog.getByLabelText(o.effectiveTo)).toHaveValue('2026-12-01')

    // Edit the committed end date down to an incomplete year: a real user revising what they already typed.
    const toYear = dateSegment(dialog, o.effectiveTo, 'year')
    await user.clear(toYear)
    await user.type(toYear, '20')
    expect(await dialog.findByText(ar.dateInput.invalidDate)).toBeInTheDocument()
    expect(dialog.getByLabelText(o.effectiveTo)).toHaveValue('')

    await user.click(dialog.getByRole('button', { name: o.recordIntentionSubmit }))

    expect(harness.posts).toHaveLength(0)
  })

  it('still allows submission when an optional end date is fully cleared back to empty', async () => {
    const { harness, user } = await renderPage({ permissions: ['hr.return_intention_periods.record'] })
    const dialog = await openAction(user, o.recordIntention)
    await user.click(dialog.getByRole('radio', { name: ar.employee360.wantsToReturn }))
    await typeVisibleDate(user, dialog, o.effectiveFrom, '20', '10', '2026')
    await typeVisibleDate(user, dialog, o.effectiveTo, '01', '12', '2026')

    await user.clear(dateSegment(dialog, o.effectiveTo, 'day'))
    await user.clear(dateSegment(dialog, o.effectiveTo, 'month'))
    await user.clear(dateSegment(dialog, o.effectiveTo, 'year'))
    expect(dialog.queryByText(ar.dateInput.invalidDate)).not.toBeInTheDocument()

    await user.click(dialog.getByRole('button', { name: o.recordIntentionSubmit }))

    await waitFor(() => expect(harness.posts).toHaveLength(1))
    expect(harness.posts[0]?.body).toEqual({ intention: 'WANTS_TO_RETURN', effective_from: '2026-10-20' })
  })
})

describe('Employee 360 operations — transfer', () => {
  const TRANSFER = { permissions: ['hr.employment_relationships.transfer'] }

  async function fillTransfer(user: User, dialog: ReturnType<typeof within>) {
    await pickOption(user, dialog, o.destinationUnit, 'مستشفى الشمال', 'unit-9')
    setDate(dialog, o.effectiveFrom, '2026-11-01')
    await pickOption(user, dialog, o.decisionType, 'نقل', 'dt-transfer')
  }

  it('explains that transfer is not a status, confirms employee/destination/date/decision, then posts the exact payload and refetches every affected stream', async () => {
    const { harness, user } = await renderPage(TRANSFER)
    await openTab(user, MOVEMENT)
    const dialog = await openAction(user, o.transfer)
    expect(dialog.getByText(o.transferNote)).toBeInTheDocument()
    await fillTransfer(user, dialog)
    const before = ['/placement-periods', '/actual-workplace', '/full-secondment-periods', '/workplace-assignment-periods', '/partial-secondment-periods'].map((part) => [part, harness.getCount(part)] as const)

    await user.click(dialog.getByRole('button', { name: o.review }))
    expect(harness.posts).toHaveLength(0)
    expect(dialog.getByText('موظف اختبار')).toBeInTheDocument()
    expect(dialog.getByText('مستشفى الشمال')).toBeInTheDocument()
    expect(dialog.getByText('01/11/2026')).toBeInTheDocument()
    expect(dialog.getByText('نقل')).toBeInTheDocument()

    await user.click(dialog.getByRole('button', { name: o.transferSubmit }))
    await waitFor(() => expect(harness.posts).toHaveLength(1))
    expect(harness.posts[0]?.url).toContain(`${BASE}/transfer`)
    expect(harness.posts[0]?.body).toEqual({ organizational_unit_id: 'unit-9', effective_from: '2026-11-01', decision_type_id: 'dt-transfer' })
    expect(await screen.findByText(o.transferSuccess)).toBeInTheDocument()
    await waitFor(() => {
      for (const [part, count] of before) expect(harness.getCount(part)).toBeGreaterThan(count)
    })
  })

  it('only offers the TRANSFER decision type', async () => {
    const { user } = await renderPage(TRANSFER)
    await openTab(user, MOVEMENT)
    const dialog = await openAction(user, o.transfer)
    await dialog.findByRole('option', { name: 'نقل' })
    expect(dialog.queryByRole('option', { name: 'تكليف' })).not.toBeInTheDocument()
  })

  it('surfaces a 403 as permission-or-scope, keeps the form, and never retries', async () => {
    const { harness, user } = await renderPage({ ...TRANSFER, onPost: () => jsonResponse({ message: 'This action is unauthorized.' }, 403) })
    await openTab(user, MOVEMENT)
    const dialog = await openAction(user, o.transfer)
    await fillTransfer(user, dialog)
    await user.click(dialog.getByRole('button', { name: o.review }))
    await user.click(dialog.getByRole('button', { name: o.transferSubmit }))
    expect(await dialog.findByText(o.errorForbidden)).toBeInTheDocument()
    expect(dialog.queryByText('This action is unauthorized.')).not.toBeInTheDocument()
    expect(harness.posts).toHaveLength(1)
    // The review summary stays up with the failure; going back shows every entered value preserved.
    await user.click(dialog.getByRole('button', { name: o.back }))
    expect(dialog.getByLabelText(o.effectiveFrom)).toHaveValue('2026-11-01')
  })

  it('surfaces a 422 domain rejection on the field without rewriting the date', async () => {
    const { user } = await renderPage({
      ...TRANSFER,
      onPost: () => jsonResponse({ message: 'Rejected.', errors: { effective_from: ['A later placement already exists.'] } }, 422),
    })
    await openTab(user, MOVEMENT)
    const dialog = await openAction(user, o.transfer)
    await fillTransfer(user, dialog)
    await user.click(dialog.getByRole('button', { name: o.review }))
    await user.click(dialog.getByRole('button', { name: o.transferSubmit }))
    expect(await dialog.findByText('A later placement already exists.')).toBeInTheDocument()
    expect(dialog.getByLabelText(o.effectiveFrom)).toHaveValue('2026-11-01')
  })

  it('disables the submit button while pending so a double click posts once', async () => {
    let release: () => void = () => {}
    const gate = new Promise<Response>((resolve) => {
      release = () => resolve(jsonResponse({}, 201))
    })
    const { harness, user } = await renderPage({ ...TRANSFER, onPost: () => gate })
    await openTab(user, MOVEMENT)
    const dialog = await openAction(user, o.transfer)
    await fillTransfer(user, dialog)
    await user.click(dialog.getByRole('button', { name: o.review }))
    const submit = dialog.getByRole('button', { name: o.transferSubmit })
    await user.click(submit)
    await waitFor(() => expect(dialog.getByRole('button', { name: o.saving })).toBeDisabled())
    await user.click(dialog.getByRole('button', { name: o.saving }))
    expect(harness.posts).toHaveLength(1)
    release()
    expect(await screen.findByText(o.transferSuccess)).toBeInTheDocument()
  })

  it('moves to the sign-in page when the session has ended (401)', async () => {
    const { user } = await renderPage({ ...TRANSFER, onPost: () => jsonResponse({ message: 'Unauthenticated.' }, 401) })
    await openTab(user, MOVEMENT)
    const dialog = await openAction(user, o.transfer)
    await fillTransfer(user, dialog)
    await user.click(dialog.getByRole('button', { name: o.review }))
    await user.click(dialog.getByRole('button', { name: o.transferSubmit }))
    expect(await screen.findByRole('heading', { level: 1, name: ar.auth.pageTitle })).toBeInTheDocument()
  })

  /** The refetch streams a successful transfer touches (see the success test above) — none may move on a failure. */
  const REFETCHED_STREAMS = ['/placement-periods', '/actual-workplace', '/full-secondment-periods', '/workplace-assignment-periods', '/partial-secondment-periods']

  it('surfaces a 404 for a missing referenced unit/decision type, leaks no raw server text (message, detail, or a per-field error), keeps the form pending-free with every value intact, and never retries or refetches', async () => {
    const { harness, user } = await renderPage({
      ...TRANSFER,
      // A field-level `errors` entry is included deliberately: mutationError.ts still passes `fieldErrors`
      // through unfiltered on a 404 (only `message`/`detail` were hardcoded to generic), so a backend 404 body
      // naming the field could still leak through `fieldErrorFor` even with `detail: null`. This must not render.
      onPost: () => jsonResponse({ message: 'Organizational unit not found.', errors: { organizational_unit_id: ['Unit ref 9f3 missing from catalog.'] } }, 404),
    })
    await openTab(user, MOVEMENT)
    const dialog = await openAction(user, o.transfer)
    await fillTransfer(user, dialog)
    const before = REFETCHED_STREAMS.map((part) => [part, harness.getCount(part)] as const)
    await user.click(dialog.getByRole('button', { name: o.review }))
    await user.click(dialog.getByRole('button', { name: o.transferSubmit }))
    expect(await dialog.findByText(o.errorNotFound)).toBeInTheDocument()
    expect(dialog.queryByText('Organizational unit not found.')).not.toBeInTheDocument()
    // Pending ended: the submit button is back to its normal label and enabled, not stuck on "saving".
    expect(dialog.getByRole('button', { name: o.transferSubmit })).toBeEnabled()
    // No success and no refetch of any stream a real success would have refreshed.
    expect(screen.queryByText(o.transferSuccess)).not.toBeInTheDocument()
    for (const [part, count] of before) expect(harness.getCount(part)).toBe(count)
    expect(harness.posts).toHaveLength(1)
    // The review summary stays up with the failure; going back shows every entered value preserved. The review
    // phase does not mount the destination-unit field at all, so the field-level leak can only be checked once
    // back in edit phase, where `fieldErrorFor` actually wires `failure.fieldErrors` to that field's `error` prop.
    await user.click(dialog.getByRole('button', { name: o.back }))
    expect(dialog.getByLabelText(o.effectiveFrom)).toHaveValue('2026-11-01')
    expect(dialog.queryByText('Unit ref 9f3 missing from catalog.')).not.toBeInTheDocument()
    expect(dialog.getByLabelText(o.destinationUnit)).not.toHaveAttribute('aria-invalid', 'true')
  })

  it('surfaces a network failure (fetch rejects) distinctly, keeps the form pending-free with every value intact, and never retries or refetches', async () => {
    const { harness, user } = await renderPage({ ...TRANSFER, onPost: () => Promise.reject(new TypeError('Failed to fetch')) })
    await openTab(user, MOVEMENT)
    const dialog = await openAction(user, o.transfer)
    await fillTransfer(user, dialog)
    const before = REFETCHED_STREAMS.map((part) => [part, harness.getCount(part)] as const)
    await user.click(dialog.getByRole('button', { name: o.review }))
    await user.click(dialog.getByRole('button', { name: o.transferSubmit }))
    expect(await dialog.findByText(ar.errors.network)).toBeInTheDocument()
    expect(dialog.getByRole('button', { name: o.transferSubmit })).toBeEnabled()
    expect(screen.queryByText(o.transferSuccess)).not.toBeInTheDocument()
    for (const [part, count] of before) expect(harness.getCount(part)).toBe(count)
    expect(harness.posts).toHaveLength(1)
    await user.click(dialog.getByRole('button', { name: o.back }))
    expect(dialog.getByLabelText(o.effectiveFrom)).toHaveValue('2026-11-01')
  })

  it('surfaces a request timeout distinctly from a generic network failure, keeps the form pending-free with every value intact, and never retries or refetches', async () => {
    const { harness, user } = await renderPage({ ...TRANSFER, onPost: () => Promise.reject(new DOMException('Timed out.', 'TimeoutError')) })
    await openTab(user, MOVEMENT)
    const dialog = await openAction(user, o.transfer)
    await fillTransfer(user, dialog)
    const before = REFETCHED_STREAMS.map((part) => [part, harness.getCount(part)] as const)
    await user.click(dialog.getByRole('button', { name: o.review }))
    await user.click(dialog.getByRole('button', { name: o.transferSubmit }))
    expect(await dialog.findByText(ar.errors.timeout)).toBeInTheDocument()
    expect(dialog.queryByText(ar.errors.network)).not.toBeInTheDocument()
    expect(dialog.getByRole('button', { name: o.transferSubmit })).toBeEnabled()
    expect(screen.queryByText(o.transferSuccess)).not.toBeInTheDocument()
    for (const [part, count] of before) expect(harness.getCount(part)).toBe(count)
    expect(harness.posts).toHaveLength(1)
    await user.click(dialog.getByRole('button', { name: o.back }))
    expect(dialog.getByLabelText(o.effectiveFrom)).toHaveValue('2026-11-01')
  })
})

describe('Employee 360 operations — full secondment and workplace assignment', () => {
  it('starts a full secondment with the contract fields only', async () => {
    const { harness, user } = await renderPage({ permissions: ['hr.full_secondment_periods.start'] })
    await openTab(user, MOVEMENT)
    const dialog = await openAction(user, o.startSecondment)
    expect(dialog.getByText(o.secondmentNote)).toBeInTheDocument()
    await pickOption(user, dialog, o.destinationUnit, 'مستشفى الشمال', 'unit-9')
    setDate(dialog, o.effectiveFrom, '2026-11-01')
    await user.click(dialog.getByRole('button', { name: o.review }))
    await user.click(dialog.getByRole('button', { name: o.startSecondmentSubmit }))
    await waitFor(() => expect(harness.posts).toHaveLength(1))
    expect(harness.posts[0]?.url).toContain(`${BASE}/full-secondment-periods`)
    expect(harness.posts[0]?.body).toEqual({ organizational_unit_id: 'unit-9', effective_from: '2026-11-01' })
    expect(await screen.findByText(o.startSecondmentSuccess)).toBeInTheDocument()
  })

  it('ends an open full secondment with effective_to only and does not touch employment status', async () => {
    const { harness, user } = await renderPage({
      permissions: ['hr.full_secondment_periods.end'],
      secondments: [{ id: 'fs-1', employment_relationship_id: 'rel-1', organizational_unit_id: 'unit-9', effective_from: '2026-01-01', effective_to: null }],
    })
    await openTab(user, MOVEMENT)
    const dialog = await openAction(user, o.endSecondment)
    setDate(dialog, o.effectiveTo, '2026-12-01')
    await user.click(dialog.getByRole('button', { name: o.review }))
    await user.click(dialog.getByRole('button', { name: o.endSecondmentSubmit }))
    await waitFor(() => expect(harness.posts).toHaveLength(1))
    expect(harness.posts[0]?.url).toContain(`${BASE}/full-secondment-periods/end`)
    expect(harness.posts[0]?.body).toEqual({ effective_to: '2026-12-01' })
    expect(harness.posts.every((post) => !post.url.includes('/status-periods'))).toBe(true)
  })

  it('starts a workplace assignment with the ASSIGNMENT decision type', async () => {
    const { harness, user } = await renderPage({ permissions: ['hr.workplace_assignment_periods.start'] })
    await openTab(user, MOVEMENT)
    const dialog = await openAction(user, o.startAssignment)
    await pickOption(user, dialog, o.destinationUnit, 'مستشفى الشمال', 'unit-9')
    setDate(dialog, o.effectiveFrom, '2026-11-01')
    await pickOption(user, dialog, o.decisionType, 'تكليف', 'dt-assign')
    expect(dialog.queryByRole('option', { name: 'نقل' })).not.toBeInTheDocument()
    await user.click(dialog.getByRole('button', { name: o.review }))
    await user.click(dialog.getByRole('button', { name: o.startAssignmentSubmit }))
    await waitFor(() => expect(harness.posts).toHaveLength(1))
    expect(harness.posts[0]?.url).toContain(`${BASE}/workplace-assignment-periods`)
    expect(harness.posts[0]?.body).toEqual({ organizational_unit_id: 'unit-9', effective_from: '2026-11-01', decision_type_id: 'dt-assign' })
  })

  it('ends an open workplace assignment with effective_to only', async () => {
    const { harness, user } = await renderPage({
      permissions: ['hr.workplace_assignment_periods.end'],
      assignments: [{ id: 'wa-1', employment_relationship_id: 'rel-1', organizational_unit_id: 'unit-9', effective_from: '2026-01-01', effective_to: null }],
    })
    await openTab(user, MOVEMENT)
    const dialog = await openAction(user, o.endAssignment)
    setDate(dialog, o.effectiveTo, '2026-12-01')
    await user.click(dialog.getByRole('button', { name: o.review }))
    await user.click(dialog.getByRole('button', { name: o.endAssignmentSubmit }))
    await waitFor(() => expect(harness.posts).toHaveLength(1))
    expect(harness.posts[0]?.url).toContain(`${BASE}/workplace-assignment-periods/end`)
    expect(harness.posts[0]?.body).toEqual({ effective_to: '2026-12-01' })
  })
})

describe('Employee 360 operations — partial secondment and work schedule', () => {
  it('posts the weekday codes and bounded dates, states it is not attendance/FTE, and offers no stop action', async () => {
    const { harness, user } = await renderPage({ permissions: ['hr.partial_secondment_periods.record'] })
    await openTab(user, MOVEMENT)
    expect(screen.getAllByRole('button', { name: /جزئي/ })).toHaveLength(1)
    const dialog = await openAction(user, o.recordPartial)
    expect(dialog.getByText(o.partialNote)).toBeInTheDocument()
    await pickOption(user, dialog, o.destinationUnit, 'مستشفى الشمال', 'unit-9')
    setDate(dialog, o.effectiveFrom, '2026-11-01')
    setDate(dialog, o.effectiveTo, '2027-02-01')
    await user.click(dialog.getByRole('checkbox', { name: ar.employee360.weekdayMonday }))
    await user.click(dialog.getByRole('checkbox', { name: ar.employee360.weekdayWednesday }))
    await user.click(dialog.getByRole('button', { name: o.review }))
    await user.click(dialog.getByRole('button', { name: o.partialSubmit }))
    await waitFor(() => expect(harness.posts).toHaveLength(1))
    expect(harness.posts[0]?.url).toContain(`${BASE}/partial-secondment-periods`)
    expect(harness.posts[0]?.body).toEqual({ organizational_unit_id: 'unit-9', effective_from: '2026-11-01', effective_to: '2027-02-01', weekdays: ['MONDAY', 'WEDNESDAY'] })
  })

  it('requires at least one weekday', async () => {
    const { harness, user } = await renderPage({ permissions: ['hr.partial_secondment_periods.record'] })
    await openTab(user, MOVEMENT)
    const dialog = await openAction(user, o.recordPartial)
    await pickOption(user, dialog, o.destinationUnit, 'مستشفى الشمال', 'unit-9')
    setDate(dialog, o.effectiveFrom, '2026-11-01')
    await user.click(dialog.getByRole('button', { name: o.review }))
    expect(await dialog.findByText(o.weekdaysRequired)).toBeInTheDocument()
    expect(harness.posts).toHaveLength(0)
  })

  it('records a work schedule as a weekday pattern (no attendance semantics) and refetches the schedule', async () => {
    const { harness, user } = await renderPage({ permissions: ['hr.work_schedule_periods.record'] })
    await openTab(user, SCHEDULE)
    const dialog = await openAction(user, o.recordSchedule)
    expect(dialog.getByText(o.scheduleNote)).toBeInTheDocument()
    setDate(dialog, o.effectiveFrom, '2026-11-01')
    for (const label of [ar.employee360.weekdaySunday, ar.employee360.weekdayMonday]) {
      await user.click(dialog.getByRole('checkbox', { name: label }))
    }
    const before = harness.getCount('/work-schedule-periods')
    await user.click(dialog.getByRole('button', { name: o.scheduleSubmit }))
    await waitFor(() => expect(harness.posts).toHaveLength(1))
    expect(harness.posts[0]?.url).toContain(`${BASE}/work-schedule-periods`)
    expect(harness.posts[0]?.body).toEqual({ effective_from: '2026-11-01', weekdays: ['SUNDAY', 'MONDAY'] })
    await waitFor(() => expect(harness.getCount('/work-schedule-periods')).toBeGreaterThan(before))
    expect(await screen.findByText(o.scheduleSuccess)).toBeInTheDocument()
  })
})

describe('Employee 360 operations — employment relationship end', () => {
  const END = { permissions: ['hr.employment_relationships.end'] }

  async function fillEnd(user: User, dialog: ReturnType<typeof within>) {
    setDate(dialog, o.effectiveTo, '2026-12-31')
    await user.click(dialog.getByRole('radio', { name: new RegExp(o.terminalYes) }))
  }

  it('requires an explicit review, then sends expected_version, effective_to and is_terminal', async () => {
    const { harness, user } = await renderPage(END)
    await openTab(user, EMPLOYMENT)
    const dialog = await openAction(user, o.endRelationship)
    await fillEnd(user, dialog)
    await user.click(dialog.getByRole('button', { name: o.review }))
    expect(harness.posts).toHaveLength(0)
    expect(dialog.getByText('موظف اختبار')).toBeInTheDocument()
    expect(dialog.getByText('31/12/2026')).toBeInTheDocument()
    expect(dialog.getByText(o.terminalYes)).toBeInTheDocument()
    await user.click(dialog.getByRole('button', { name: o.endRelationshipSubmit }))
    await waitFor(() => expect(harness.posts).toHaveLength(1))
    expect(harness.posts[0]?.url).toContain(`${BASE}/end`)
    expect(harness.posts[0]?.body).toEqual({ expected_version: 7, effective_to: '2026-12-31', is_terminal: true })
    expect(await screen.findByText(o.endRelationshipSuccess)).toBeInTheDocument()
  })

  it('sends is_terminal=false for a non-terminal ending', async () => {
    const { harness, user } = await renderPage(END)
    await openTab(user, EMPLOYMENT)
    const dialog = await openAction(user, o.endRelationship)
    setDate(dialog, o.effectiveTo, '2026-12-31')
    await user.click(dialog.getByRole('radio', { name: new RegExp(o.terminalNo) }))
    await user.click(dialog.getByRole('button', { name: o.review }))
    await user.click(dialog.getByRole('button', { name: o.endRelationshipSubmit }))
    await waitFor(() => expect(harness.posts).toHaveLength(1))
    expect(harness.posts[0]?.body).toMatchObject({ is_terminal: false })
  })

  it('requires the terminal choice and the date before any review or request', async () => {
    const { harness, user } = await renderPage(END)
    await openTab(user, EMPLOYMENT)
    const dialog = await openAction(user, o.endRelationship)
    await user.click(dialog.getByRole('button', { name: o.review }))
    expect(await dialog.findAllByText(o.required)).toHaveLength(2)
    expect(harness.posts).toHaveLength(0)
  })

  it('reports a stale-version conflict distinctly, offers a refresh, and does not retry', async () => {
    const { harness, user } = await renderPage({ ...END, onPost: () => jsonResponse({ message: 'The employment relationship has already ended.' }, 409) })
    await openTab(user, EMPLOYMENT)
    const dialog = await openAction(user, o.endRelationship)
    await fillEnd(user, dialog)
    await user.click(dialog.getByRole('button', { name: o.review }))
    await user.click(dialog.getByRole('button', { name: o.endRelationshipSubmit }))
    expect(await dialog.findByText(o.errorConflict)).toBeInTheDocument()
    expect(dialog.getByRole('button', { name: o.refresh })).toBeInTheDocument()
    expect(harness.posts).toHaveLength(1)
    const reads = harness.getCount('/employment-relationships')
    await user.click(dialog.getByRole('button', { name: o.refresh }))
    await waitFor(() => expect(harness.getCount('/employment-relationships')).toBeGreaterThan(reads))
    expect(harness.posts).toHaveLength(1)
  })
})

describe('Employee 360 operations — accessibility and locale', () => {
  it('opens a named dialog whose every field has a visible label', async () => {
    const { user } = await renderPage({ permissions: ['hr.employment_relationships.transfer'] })
    await openTab(user, MOVEMENT)
    await user.click(await screen.findByRole('button', { name: o.transfer }))
    const dialog = await screen.findByRole('dialog', { name: o.transferTitle })
    for (const label of [o.destinationUnit, o.effectiveFrom, o.decisionType]) {
      expect(within(dialog).getByLabelText(label)).toBeInTheDocument()
    }
    expect(within(dialog).getByLabelText(o.effectiveFrom)).toHaveAttribute('dir', 'ltr')
  })

  it('renders the same operation in English', async () => {
    const { user } = await renderPage({ permissions: ['hr.employment_status_periods.record'] }, 'en')
    const dialog = await openAction(user, en.operations.recordStatus)
    expect(dialog.getByLabelText(en.operations.statusLabel)).toBeInTheDocument()
    expect(screen.getByRole('dialog', { name: en.operations.recordStatusTitle })).toBeInTheDocument()
  })

  it('gives the sheet close button a translated accessible name in Arabic', async () => {
    const { user } = await renderPage({ permissions: ['hr.employment_status_periods.record'] })
    const dialog = await openAction(user, o.recordStatus)
    expect(dialog.getByRole('button', { name: ar.app.close })).toBeInTheDocument()
  })

  it('gives the sheet close button a translated accessible name in English', async () => {
    const { user } = await renderPage({ permissions: ['hr.employment_status_periods.record'] }, 'en')
    const dialog = await openAction(user, en.operations.recordStatus)
    expect(dialog.getByRole('button', { name: en.app.close })).toBeInTheDocument()
  })
})
