import { screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it } from 'vitest'
import type { CurrentPrincipal } from '../features/auth/api'
import { CURRENT_PRINCIPAL_BODY, jsonResponse, renderApp, stubAppFetch } from '../test/render'

const AUTHENTICATED_HR_VIEWER: CurrentPrincipal = {
  ...CURRENT_PRINCIPAL_BODY,
  permissions: [
    ...CURRENT_PRINCIPAL_BODY.permissions,
    'hr.persons.view',
    'hr.employment_relationships.view',
    'hr.employment_status_periods.view',
    'hr.organizational_placement_periods.view',
    'hr.full_secondment_periods.view',
    'hr.workplace_assignment_periods.view',
    'hr.partial_secondment_periods.view',
    'hr.work_schedule_periods.view',
    'hr.employment_category_periods.view',
    'hr.employment_contract_periods.view',
    'hr.employment_job_title_periods.view',
    'hr.employment_specialty_periods.view',
    'hr.person_qualifications.view',
    'hr.return_intention_periods.view',
  ],
}

const PERSON = {
  id: 'person-1',
  national_id: '1234567890',
  is_terminal: false,
  version: 1,
  full_name_ar: 'موظف اختبار',
  gender_id: null,
  marital_status_id: null,
  birth_date: null,
  birth_place: null,
}

const RELATIONSHIP = {
  id: 'rel-1',
  person_id: 'person-1',
  employment_type_id: 'et-1',
  employee_number: 'EMP-001',
  employee_number_scheme: 'PERMANENT',
  effective_from: '2020-01-01',
  effective_to: null,
  end_knowledge_state: 'NOT_APPLICABLE',
  ended_terminally: null,
  version: 1,
}

const STATUS_DETAIL_CATALOG = {
  data: [
    { id: 'sd-1', category_id: 'c1', code: 'on_duty', name_ar: 'على رأس العمل', name_en: 'On duty', is_active: true, display_order: 1 },
    { id: 'sd-2', category_id: 'c2', code: 'unpaid_leave', name_ar: 'إجازة بدون راتب', name_en: 'Unpaid leave', is_active: true, display_order: 7 },
    { id: 'sd-3', category_id: 'c2', code: 'traveling', name_ar: 'مسافر', name_en: 'Traveling', is_active: true, display_order: 3 },
  ],
}

const STATUS_PERIODS = [
  { id: 'sp-1', employment_relationship_id: 'rel-1', status_detail_id: 'sd-1', effective_from: '2020-01-01', effective_to: null },
]

function effective(status: Record<string, unknown> | null, asOf = '2026-10-15') {
  return { as_of: asOf, status }
}

const EFFECTIVE_ON_DUTY = effective({
  status_detail_id: 'sd-1', status_detail_code: 'on_duty', derived: false, period_id: 'sp-1',
  derived_from_period_id: null, effective_from: '2020-01-01', effective_to: null,
})

const PLACEMENT_PERIODS = [
  { id: 'pl-1', employment_relationship_id: 'rel-1', organizational_unit_id: 'unit-1', effective_from: '2020-01-01', effective_to: null },
]

const ACTUAL_WORKPLACE = { organizational_unit_id: 'unit-1', source: 'placement', since: '2020-01-01' }

const UNIT = { id: 'unit-1', parent_id: null, name: 'الإدارة العامة للمستشفيات', is_active: true, version: 1 }

const RELATIONSHIP_STREAMS: Record<string, unknown[]> = {
  '/partial-secondment-periods': [
    { id: 'ps-1', employment_relationship_id: 'rel-1', organizational_unit_id: 'unit-2', effective_from: '2026-03-01', effective_to: '2026-06-01', weekdays: ['MONDAY', 'WEDNESDAY'] },
  ],
  '/work-schedule-periods': [
    { id: 'ws-1', employment_relationship_id: 'rel-1', effective_from: '2020-01-01', effective_to: null, weekdays: ['SUNDAY', 'MONDAY', 'TUESDAY'] },
  ],
  '/employment-category-periods': [
    { id: 'cp-1', employment_relationship_id: 'rel-1', employment_category_id: 'cat-1', effective_from: '2020-01-01', effective_to: null },
  ],
  '/employment-contract-periods': [
    { id: 'cn-1', employment_relationship_id: 'rel-1', contract_type_id: 'ct-1', effective_from: '2020-01-01', effective_to: null, contractual_effective_to: '2027-01-01', contract_end_knowledge_state: 'KNOWN' },
  ],
  '/employment-job-title-periods': [
    { id: 'jt-1', employment_relationship_id: 'rel-1', job_title_id: 'jt-a', effective_from: '2021-01-01', effective_to: null, start_knowledge_state: 'KNOWN' },
  ],
  '/employment-specialty-periods': [
    { id: 'sp-x', employment_relationship_id: 'rel-1', specialty_id: 'spec-1', effective_from: '2022-01-01', effective_to: '2024-01-01' },
  ],
}

const REFERENCE_VALUES: Record<string, { name_ar: string; name_en: string }> = {
  '/reference/employment-categories/cat-1': { name_ar: 'فئة اختبار', name_en: 'Test category' },
  '/reference/contract-types/ct-1': { name_ar: 'عقد اختبار', name_en: 'Test contract' },
  '/reference/job-titles/jt-a': { name_ar: 'مسمى اختبار', name_en: 'Test title' },
  '/reference/specialties/spec-1': { name_ar: 'تخصص اختبار', name_en: 'Test specialty' },
  '/reference/academic-degrees/deg-1': { name_ar: 'درجة اختبار', name_en: 'Test degree' },
  '/reference/qualification-types/qt-1': { name_ar: 'نوع مؤهل اختبار', name_en: 'Test qualification type' },
}

const NO_INTENTION = { as_of: '2026-10-15', return_intention: null }

function defaultRoute(url: string): Response | undefined {
  if (url.includes('/auth/me')) return jsonResponse(AUTHENTICATED_HR_VIEWER)
  if (url.includes('/return-intention-periods')) return jsonResponse([])
  if (url.includes('/return-intention')) return jsonResponse(NO_INTENTION)
  if (url.includes('/effective-status')) return jsonResponse(EFFECTIVE_ON_DUTY)
  for (const [suffix, rows] of Object.entries(RELATIONSHIP_STREAMS)) {
    if (url.includes(`/employment-relationships/rel-1${suffix}`)) return jsonResponse(rows)
  }
  if (url.includes('/hr/persons/person-1/qualifications')) {
    return jsonResponse([{ id: 'q-1', person_id: 'person-1', academic_degree_id: 'deg-1', qualification_type_id: 'qt-1' }])
  }
  for (const [path, value] of Object.entries(REFERENCE_VALUES)) {
    if (url.includes(path)) return jsonResponse({ id: path.split('/').pop(), code: 'x', ...value })
  }
  if (url.includes('/organization/units/unit-2')) return jsonResponse({ ...UNIT, id: 'unit-2', name: 'وحدة الانتداب الجزئي' })
  if (url.includes('/hr/persons/person-1') && !url.includes('employment-relationships')) return jsonResponse(PERSON)
  if (url.includes('/employment-relationships/rel-1/status-periods')) return jsonResponse(STATUS_PERIODS)
  if (url.includes('/employment-relationships/rel-1/placement-periods')) return jsonResponse(PLACEMENT_PERIODS)
  if (url.includes('/full-secondment-periods')) return jsonResponse([])
  if (url.includes('/workplace-assignment-periods')) return jsonResponse([])
  if (url.includes('/actual-workplace')) return jsonResponse(ACTUAL_WORKPLACE)
  if (url.includes('/employment-relationships') && !url.includes('/rel-1/')) return jsonResponse([RELATIONSHIP])
  if (url.includes('/organization/units/unit-1')) return jsonResponse(UNIT)
  if (url.includes('/reference/employment-status-details')) return jsonResponse(STATUS_DETAIL_CATALOG)
  return undefined
}

function stub360App(overrides?: (url: string) => Response | undefined) {
  return stubAppFetch({ overrides: (url) => overrides?.(url) ?? defaultRoute(url) })
}

/** The identity/employment header section, found by its stable heading id (locale-independent). */
async function findHeaderSection(): Promise<HTMLElement> {
  const heading = await waitFor(() => {
    const element = document.getElementById('employee-360-header-heading')
    if (!element) throw new Error('header heading not rendered yet')
    return element
  })
  const section = heading.closest('section')
  if (!section) throw new Error('expected the Employee 360 header section to be present')
  return section
}

const ROUTE = '/employees/person-1/relationships/rel-1'

describe('Employee360Page', () => {
  it('renders the identity/employment header with the resolved current status and actual workplace', async () => {
    stub360App()
    renderApp(ROUTE)

    // This is the first render in the file, so it also pays the one-time cold import of the lazy
    // Employee 360 route chunk (router.tsx). On a slower machine that can exceed Testing Library's
    // default 1000ms, so this single assertion states a longer bound; the heading itself is unchanged.
    expect(
      await screen.findByRole('heading', { level: 1, name: 'الملف الشامل للموظف' }, { timeout: 4000 }),
    ).toBeInTheDocument()
    // Scoped to the header section itself: every tabpanel now stays mounted (hidden, not
    // unmounted — see Employee360Page.tsx) so a field the Employment tab also displays, such as
    // the employee number, exists twice in the DOM once that panel is mounted. Querying the whole
    // document for 'EMP-001' would incorrectly match both; the header's own <h2> is the only
    // level-2 heading on the page, so its closest section unambiguously scopes to just the header.
    const headerSection = await findHeaderSection()
    const header = within(headerSection)
    expect(header.getByText('1234567890')).toBeInTheDocument()
    expect(header.getByText('موظف اختبار')).toBeInTheDocument()
    expect(header.getByText('EMP-001')).toBeInTheDocument()
    expect(await header.findByText('على رأس العمل')).toBeInTheDocument()
    // The resolved unit name appears in both the always-visible header and the default
    // Overview tab (original workplace) — assert presence, not a single exact match.
    expect((await screen.findAllByText('الإدارة العامة للمستشفيات')).length).toBeGreaterThan(0)
  })

  it('shows "not recorded" for a legacy person with no recorded name, never a placeholder (S24)', async () => {
    stub360App((url) =>
      url.includes('/hr/persons/person-1') && !url.includes('employment-relationships') && !url.includes('/qualifications')
        ? jsonResponse({ ...PERSON, full_name_ar: null })
        : undefined,
    )
    renderApp(ROUTE)

    const headerSection = await findHeaderSection()
    const header = within(headerSection)
    expect(await header.findByText('1234567890')).toBeInTheDocument()
    expect(header.getByText('الاسم الكامل')).toBeInTheDocument()
    expect(header.getByText('غير مسجَّل')).toBeInTheDocument()
    expect(header.queryByText('موظف اختبار')).not.toBeInTheDocument()
  })

  it('shows the current state (status, return intention, original and actual workplace) without opening any tab', async () => {
    stub360App()
    renderApp(ROUTE)

    await screen.findByRole('heading', { level: 1, name: 'الملف الشامل للموظف' })
    const workplaceCard = (await screen.findByRole('heading', { level: 3, name: 'ملخص مكان العمل' })).closest('section')
    if (!workplaceCard) throw new Error('workplace card expected')
    expect(await within(workplaceCard).findByText('مكان العمل الأصلي')).toBeInTheDocument()
    expect(within(workplaceCard).getAllByText('الإدارة العامة للمستشفيات').length).toBeGreaterThan(0)
    expect(screen.getByRole('heading', { level: 3, name: 'الحالة الوظيفية الحالية' })).toBeInTheDocument()
    expect(screen.getByRole('heading', { level: 3, name: 'الرغبة في العودة' })).toBeInTheDocument()
  })

  it('switches to the Status History tab and lists the status periods', async () => {
    stub360App()
    const user = userEvent.setup()
    renderApp(ROUTE)

    await screen.findByRole('heading', { level: 1, name: 'الملف الشامل للموظف' })
    await user.click(screen.getByRole('tab', { name: 'سجل الحالات الوظيفية' }))

    const timeline = await screen.findByRole('list', { name: 'الخط الزمني للحالات الوظيفية' })
    expect(within(timeline).getByText('على رأس العمل')).toBeInTheDocument()
    expect(within(timeline).getByText('مستمرة')).toBeInTheDocument()
  })

  it('switches to the Movement Timeline tab and shows the placement period, with no fabricated "transfer" entries', async () => {
    stub360App()
    const user = userEvent.setup()
    renderApp(ROUTE)

    await screen.findByRole('heading', { level: 1, name: 'الملف الشامل للموظف' })
    await user.click(screen.getByRole('tab', { name: 'الخط الزمني للحركات' }))

    const timeline = await screen.findByRole('list', { name: 'الخط الزمني للحركات' })
    expect(within(timeline).getByText('إلحاق تنظيمي')).toBeInTheDocument()
    expect(within(timeline).queryByText(/نقل/)).not.toBeInTheDocument()
  })

  it('switches to the Employment tab and shows the full relationship record', async () => {
    stub360App()
    const user = userEvent.setup()
    renderApp(ROUTE)

    await screen.findByRole('heading', { level: 1, name: 'الملف الشامل للموظف' })
    await user.click(screen.getByRole('tab', { name: 'التوظيف' }))

    const panel = await screen.findByRole('tabpanel')
    expect(within(panel).getByText('سارية')).toBeInTheDocument()
    expect(within(panel).getByText('2020-01-01')).toBeInTheDocument()
  })

  it('shows an unauthorized state for a section the principal cannot view, without blanking the rest of the page', async () => {
    stub360App((url) =>
      url.includes('/full-secondment-periods') ? jsonResponse({ message: 'This action is unauthorized.' }, 403) : undefined,
    )
    const user = userEvent.setup()
    renderApp(ROUTE)

    await screen.findByRole('heading', { level: 1, name: 'الملف الشامل للموظف' })
    await user.click(screen.getByRole('tab', { name: 'الخط الزمني للحركات' }))

    expect(await screen.findByText('تعذّر تحميل جزء من بيانات الحركات')).toBeInTheDocument()
    // The placement period (a different, still-authorized source) must still render.
    expect(await screen.findByText('إلحاق تنظيمي')).toBeInTheDocument()
  })

  it('shows a not-found state for a relationship id that does not belong to the person', async () => {
    stub360App()
    renderApp('/employees/person-1/relationships/does-not-exist')

    expect(await screen.findByText('الصفحة غير موجودة')).toBeInTheDocument()
  })

  it('shows a not-found state for an unknown person id', async () => {
    stub360App((url) =>
      url.includes('/hr/persons/no-such-person') ? jsonResponse({ message: 'Not found.' }, 404) : undefined,
    )
    renderApp('/employees/no-such-person/relationships/rel-1')

    expect(await screen.findByText('الصفحة غير موجودة')).toBeInTheDocument()
  })

  it('never offers a write control (transfer/secondment/assignment/status-transition/end)', async () => {
    stub360App()
    renderApp(ROUTE)

    await screen.findByRole('heading', { level: 1, name: 'الملف الشامل للموظف' })
    expect(screen.queryByRole('button', { name: /نقل|انتداب|تكليف|إنهاء/ })).not.toBeInTheDocument()
  })

  describe('S33 effective status and existing history', () => {
    it('shows a bounded temporary status as the current status while it is effective', async () => {
      stub360App((url) =>
        url.includes('/effective-status')
          ? jsonResponse(effective({ status_detail_id: 'sd-2', status_detail_code: 'unpaid_leave', derived: false, period_id: 'sp-2', derived_from_period_id: null, effective_from: '2026-10-01', effective_to: '2026-11-01' }))
          : url.includes('/rel-1/status-periods')
            ? jsonResponse([
                { id: 'sp-1', employment_relationship_id: 'rel-1', status_detail_id: 'sd-1', effective_from: '2020-01-01', effective_to: '2026-10-01' },
                { id: 'sp-2', employment_relationship_id: 'rel-1', status_detail_id: 'sd-2', effective_from: '2026-10-01', effective_to: '2026-11-01' },
              ])
            : undefined,
      )
      renderApp(ROUTE)

      const headerSection = await findHeaderSection()
      // No open persisted period exists, yet the current status is the temporary one.
      expect(await within(headerSection).findByText('إجازة بدون راتب')).toBeInTheDocument()
      expect(within(headerSection).queryByText(/مشتقّة/)).not.toBeInTheDocument()
      const statusCard = (await screen.findByRole('heading', { level: 3, name: 'الحالة الوظيفية الحالية' })).closest('section')
      if (!statusCard) throw new Error('current status card expected')
      expect(await within(statusCard).findByText('إجازة بدون راتب')).toBeInTheDocument()
      expect(within(statusCard).getByText('2026-10-01')).toBeInTheDocument()
    })

    it('shows the derived on_duty after an expired bounded status, labelled derived, and never as a history row', async () => {
      const fetchMock = stub360App((url) =>
        url.includes('/effective-status')
          ? jsonResponse(effective({ status_detail_id: 'sd-1', status_detail_code: 'on_duty', derived: true, period_id: null, derived_from_period_id: 'sp-2', effective_from: '2026-11-01', effective_to: null }, '2026-11-05'))
          : url.includes('/rel-1/status-periods')
            ? jsonResponse([{ id: 'sp-2', employment_relationship_id: 'rel-1', status_detail_id: 'sd-2', effective_from: '2026-10-01', effective_to: '2026-11-01' }])
            : undefined,
      )
      const user = userEvent.setup()
      renderApp(ROUTE)

      const headerSection = await findHeaderSection()
      expect(await within(headerSection).findByText('على رأس العمل')).toBeInTheDocument()
      expect(within(headerSection).getByText(/مشتقّة/)).toBeInTheDocument()

      await user.click(screen.getByRole('tab', { name: 'سجل الحالات الوظيفية' }))
      const timeline = await screen.findByRole('list', { name: 'الخط الزمني للحالات الوظيفية' })
      // Persisted history only: the single recorded leave period; no synthetic on_duty row.
      expect(within(timeline).getAllByRole('listitem')).toHaveLength(1)
      expect(within(timeline).getByText('إجازة بدون راتب')).toBeInTheDocument()
      expect(within(timeline).queryByText('على رأس العمل')).not.toBeInTheDocument()
      // Read-only: every request was a GET (nothing is persisted for the derived status).
      const methods = (fetchMock.mock.calls as unknown[][]).map(([, init]) => (init as RequestInit | undefined)?.method ?? 'GET')
      expect(methods.every((method) => method === 'GET')).toBe(true)
    })

    it('shows an explicit successor at the boundary as the current status', async () => {
      stub360App((url) =>
        url.includes('/effective-status')
          ? jsonResponse(effective({ status_detail_id: 'sd-3', status_detail_code: 'traveling', derived: false, period_id: 'sp-3', derived_from_period_id: null, effective_from: '2026-11-01', effective_to: null }, '2026-11-01'))
          : undefined,
      )
      renderApp(ROUTE)
      const headerSection = await findHeaderSection()
      expect(await within(headerSection).findByText('مسافر')).toBeInTheDocument()
      expect(within(headerSection).queryByText(/مشتقّة/)).not.toBeInTheDocument()
    })

    it('shows a no-effective-status message when the backend resolves none', async () => {
      stub360App((url) => (url.includes('/effective-status') ? jsonResponse(effective(null)) : undefined))
      renderApp(ROUTE)
      const headerSection = await findHeaderSection()
      expect(await within(headerSection).findByText('لا توجد حالة وظيفية فعّالة في هذا التاريخ.')).toBeInTheDocument()
    })

    it('labels the derived status in English too (locale-aware, LTR)', async () => {
      stub360App((url) =>
        url.includes('/effective-status')
          ? jsonResponse(effective({ status_detail_id: 'sd-1', status_detail_code: 'on_duty', derived: true, period_id: null, derived_from_period_id: 'sp-2', effective_from: '2026-11-01', effective_to: null }))
          : undefined,
      )
      renderApp(ROUTE, 'en')
      const headerSection = await findHeaderSection()
      expect(await within(headerSection).findByText('On duty')).toBeInTheDocument()
      expect(within(headerSection).getByText(/derived: automatic return/)).toBeInTheDocument()
    })

    it('exposes the partial secondment and work schedule history', async () => {
      stub360App()
      const user = userEvent.setup()
      renderApp(ROUTE)
      await screen.findByRole('heading', { level: 1, name: 'الملف الشامل للموظف' })
      await user.click(screen.getByRole('tab', { name: 'ترتيبات العمل' }))

      const panel = await screen.findByRole('tabpanel')
      expect(await within(panel).findByText('وحدة الانتداب الجزئي')).toBeInTheDocument()
      expect(within(panel).getByText('الاثنين، الأربعاء')).toBeInTheDocument()
      expect(within(panel).getByText('2026-06-01')).toBeInTheDocument()
      expect(within(panel).getByText('الأحد، الاثنين، الثلاثاء')).toBeInTheDocument()
    })

    it('exposes category, contract, job title, specialty and qualification history with resolved names', async () => {
      stub360App()
      const user = userEvent.setup()
      renderApp(ROUTE)
      await screen.findByRole('heading', { level: 1, name: 'الملف الشامل للموظف' })
      await user.click(screen.getByRole('tab', { name: 'المسار الوظيفي والمؤهلات' }))

      const panel = await screen.findByRole('tabpanel')
      expect(await within(panel).findByText('فئة اختبار')).toBeInTheDocument()
      expect(await within(panel).findByText('عقد اختبار')).toBeInTheDocument()
      expect(within(panel).getByText('2027-01-01')).toBeInTheDocument()
      expect(await within(panel).findByText('مسمى اختبار')).toBeInTheDocument()
      expect(await within(panel).findByText('تخصص اختبار')).toBeInTheDocument()
      expect(within(panel).getByText('2024-01-01')).toBeInTheDocument()
      expect(await within(panel).findByText('درجة اختبار')).toBeInTheDocument()
      expect(await within(panel).findByText('نوع مؤهل اختبار')).toBeInTheDocument()
      // History timelines are labelled as history and no supervisory concept appears.
      expect(within(panel).getAllByText('خط زمني تاريخي للفترات المسجَّلة، وليس الحالة الحالية.').length).toBeGreaterThan(0)
      expect(within(panel).queryByText(/إشراف/)).not.toBeInTheDocument()
    })

    it('shows an unauthorized state for one history stream without blanking the others', async () => {
      stub360App((url) =>
        url.includes('/employment-contract-periods') ? jsonResponse({ message: 'This action is unauthorized.' }, 403) : undefined,
      )
      const user = userEvent.setup()
      renderApp(ROUTE)
      await screen.findByRole('heading', { level: 1, name: 'الملف الشامل للموظف' })
      await user.click(screen.getByRole('tab', { name: 'المسار الوظيفي والمؤهلات' }))

      const panel = await screen.findByRole('tabpanel')
      expect(await within(panel).findByText('فئة اختبار')).toBeInTheDocument()
      expect(await within(panel).findByText('حسابك لا يملك الصلاحية اللازمة لعرض هذا القسم.')).toBeInTheDocument()
    })

    it('falls back to the raw id when a catalog value cannot be resolved, never an invented name', async () => {
      stub360App((url) => (url.includes('/reference/job-titles/') ? jsonResponse({ message: 'Forbidden' }, 403) : undefined))
      const user = userEvent.setup()
      renderApp(ROUTE)
      await screen.findByRole('heading', { level: 1, name: 'الملف الشامل للموظف' })
      await user.click(screen.getByRole('tab', { name: 'المسار الوظيفي والمؤهلات' }))
      expect(await within(await screen.findByRole('tabpanel')).findByText('jt-a')).toBeInTheDocument()
    })
  })

  describe('S35 relationship end vs movements', () => {
    const ENDED = { ...RELATIONSHIP, effective_to: '2026-11-01', end_knowledge_state: 'KNOWN', ended_terminally: false }
    const CASE1_FULL = { id: 'fs-1', employment_relationship_id: 'rel-1', organizational_unit_id: 'unit-1', effective_from: '2026-10-01', effective_to: '2026-11-01' }
    const CASE3_ASSIGNMENT = { id: 'wa-1', employment_relationship_id: 'rel-1', organizational_unit_id: 'unit-1', effective_from: '2026-12-01', effective_to: null }

    function endedRelationshipRoutes(url: string) {
      if (url.includes('/employment-relationships') && !url.includes('/rel-1/')) return jsonResponse([ENDED])
      if (url.includes('/actual-workplace')) return jsonResponse({ organizational_unit_id: null, source: null, since: null })
      if (url.includes('/full-secondment-periods')) return jsonResponse([CASE1_FULL])
      if (url.includes('/workplace-assignment-periods')) return jsonResponse([CASE3_ASSIGNMENT])
      return undefined
    }

    it('shows no current workplace after the end, even though a future movement row physically survives', async () => {
      stub360App(endedRelationshipRoutes)
      renderApp(ROUTE)

      const headerSection = await findHeaderSection()
      expect(await within(headerSection).findByText('غير محدَّد')).toBeInTheDocument()
      expect(within(headerSection).queryByText('الإدارة العامة للمستشفيات')).not.toBeInTheDocument()
    })

    it('shows the truncated Case 1 end date and the recorded Case 3 dates as history only', async () => {
      stub360App(endedRelationshipRoutes)
      const user = userEvent.setup()
      renderApp(ROUTE)

      await screen.findByRole('heading', { level: 1, name: 'الملف الشامل للموظف' })
      await user.click(screen.getByRole('tab', { name: 'الخط الزمني للحركات' }))
      const timeline = await screen.findByRole('list', { name: 'الخط الزمني للحركات' })

      const rows = within(timeline).getAllByRole('listitem')
      const full = rows.find((row) => within(row).queryByText('انتداب كلي'))
      const assignment = rows.find((row) => within(row).queryByText('تكليف'))
      if (!full || !assignment) throw new Error('both movement rows expected')
      expect(within(full).getByText('2026-10-01')).toBeInTheDocument()
      expect(within(full).getByText('2026-11-01')).toBeInTheDocument()
      expect(within(assignment).getByText('2026-12-01')).toBeInTheDocument()
      // The timeline is history: it is never labelled as the current/actual workplace.
      expect(within(timeline).queryByText(/الحالي|الفعلي/)).not.toBeInTheDocument()
    })
  })

  describe('S34 return intention (independent of employment status)', () => {
    const CURRENT = {
      as_of: '2026-10-15',
      return_intention: { period_id: 'ri-1', intention: 'WANTS_TO_RETURN', effective_from: '2026-10-05', effective_to: null },
    }
    const HISTORY = [
      { id: 'ri-0', employment_relationship_id: 'rel-1', intention: 'DOES_NOT_WANT_TO_RETURN', effective_from: '2026-08-01', effective_to: '2026-10-05' },
      { id: 'ri-1', employment_relationship_id: 'rel-1', intention: 'WANTS_TO_RETURN', effective_from: '2026-10-05', effective_to: null },
    ]

    function withIntention(current: unknown, history: unknown = HISTORY) {
      return (url: string) =>
        url.includes('/return-intention-periods')
          ? jsonResponse(history)
          : url.includes('/return-intention')
            ? jsonResponse(current)
            : undefined
    }

    it('shows the current return intention separately from the effective status, alongside a temporary status', async () => {
      stub360App((url) =>
        withIntention(CURRENT)(url) ??
        (url.includes('/effective-status')
          ? jsonResponse(effective({ status_detail_id: 'sd-3', status_detail_code: 'traveling', derived: false, period_id: 'sp-3', derived_from_period_id: null, effective_from: '2026-10-01', effective_to: null }))
          : undefined),
      )
      renderApp(ROUTE)

      const headerSection = await findHeaderSection()
      const header = within(headerSection)
      expect(await header.findByText('مسافر')).toBeInTheDocument()
      const rows = header.getAllByRole('term').map((term) => term.textContent)
      expect(rows).toContain('الرغبة في العودة')
      expect(await header.findByText('يرغب في العودة')).toBeInTheDocument()
      expect(header.getByText('2026-10-05')).toBeInTheDocument()
      // The status text never carries the intention and vice versa.
      expect(header.queryByText(/مسافر.*يرغب/)).not.toBeInTheDocument()
    })

    it('shows "not recorded" when no intention exists, never either intention', async () => {
      stub360App()
      renderApp(ROUTE)

      const headerSection = await findHeaderSection()
      expect(await within(headerSection).findByText('غير مسجَّلة')).toBeInTheDocument()
      expect(within(headerSection).queryByText(/^(يرغب|لا يرغب) في العودة/)).not.toBeInTheDocument()
    })

    it('shows the return intention history as its own section, separate from the status history table', async () => {
      stub360App(withIntention(CURRENT))
      const user = userEvent.setup()
      renderApp(ROUTE)
      await screen.findByRole('heading', { level: 1, name: 'الملف الشامل للموظف' })
      await user.click(screen.getByRole('tab', { name: 'سجل الحالات الوظيفية' }))

      const panel = await screen.findByRole('tabpanel')
      const intentionSection = (await within(panel).findByRole('heading', { name: 'سجل الرغبة في العودة' })).closest('section')
      if (!intentionSection) throw new Error('intention history section expected')
      const intention = within(intentionSection)
      expect(await intention.findByText('لا يرغب في العودة')).toBeInTheDocument()
      expect(intention.getAllByText('2026-10-05', { selector: 'bdi' })).toHaveLength(2)
      // The status timeline contains no return-intention entry and the section states independence.
      const statusTimeline = within(panel).getByRole('list', { name: 'الخط الزمني للحالات الوظيفية' })
      expect(within(statusTimeline).queryByText(/يرغب في العودة/)).not.toBeInTheDocument()
      expect(intention.getByText(/مستقل عن الحالة الوظيفية/)).toBeInTheDocument()
    })

    it('shows an empty state for the return intention history', async () => {
      stub360App()
      const user = userEvent.setup()
      renderApp(ROUTE)
      await screen.findByRole('heading', { level: 1, name: 'الملف الشامل للموظف' })
      await user.click(screen.getByRole('tab', { name: 'سجل الحالات الوظيفية' }))
      expect(await within(await screen.findByRole('tabpanel')).findByText('لا يوجد سجل رغبة في العودة.')).toBeInTheDocument()
    })

    it('shows an unauthorized state for the intention without blanking the status or the rest of the page', async () => {
      stub360App((url) => (url.includes('/return-intention') ? jsonResponse({ message: 'This action is unauthorized.' }, 403) : undefined))
      const user = userEvent.setup()
      renderApp(ROUTE)

      const headerSection = await findHeaderSection()
      expect(await within(headerSection).findByText('على رأس العمل')).toBeInTheDocument()

      await user.click(screen.getByRole('tab', { name: 'سجل الحالات الوظيفية' }))
      const panel = await screen.findByRole('tabpanel')
      expect(await within(panel).findByText('حسابك لا يملك الصلاحية اللازمة لعرض هذا القسم.')).toBeInTheDocument()
      expect(within(panel).getByRole('list', { name: 'الخط الزمني للحالات الوظيفية' })).toBeInTheDocument() // the persisted status history still renders
    })

    it('renders the intention in English (locale-aware)', async () => {
      stub360App(withIntention(CURRENT))
      renderApp(ROUTE, 'en')
      const headerSection = await findHeaderSection()
      expect(await within(headerSection).findByText('Wants to return')).toBeInTheDocument()
      expect(within(headerSection).getByText('2026-10-05')).toBeInTheDocument()
    })
  })
})
