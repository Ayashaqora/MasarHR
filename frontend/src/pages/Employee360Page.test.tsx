import { screen, within } from '@testing-library/react'
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
  ],
}

const PERSON = { id: 'person-1', national_id: '1234567890', is_terminal: false, version: 1 }

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
  ],
}

const STATUS_PERIODS = [
  { id: 'sp-1', employment_relationship_id: 'rel-1', status_detail_id: 'sd-1', effective_from: '2020-01-01', effective_to: null },
]

const PLACEMENT_PERIODS = [
  { id: 'pl-1', employment_relationship_id: 'rel-1', organizational_unit_id: 'unit-1', effective_from: '2020-01-01', effective_to: null },
]

const ACTUAL_WORKPLACE = { organizational_unit_id: 'unit-1', source: 'placement', since: '2020-01-01' }

const UNIT = { id: 'unit-1', parent_id: null, name: 'الإدارة العامة للمستشفيات', is_active: true, version: 1 }

function defaultRoute(url: string): Response | undefined {
  if (url.includes('/auth/me')) return jsonResponse(AUTHENTICATED_HR_VIEWER)
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

const ROUTE = '/employees/person-1/relationships/rel-1'

describe('Employee360Page', () => {
  it('renders the identity/employment header with the resolved current status and actual workplace', async () => {
    stub360App()
    renderApp(ROUTE)

    expect(await screen.findByRole('heading', { level: 1, name: 'الملف الشامل للموظف' })).toBeInTheDocument()
    // Scoped to the header section itself: every tabpanel now stays mounted (hidden, not
    // unmounted — see Employee360Page.tsx) so a field the Employment tab also displays, such as
    // the employee number, exists twice in the DOM once that panel is mounted. Querying the whole
    // document for 'EMP-001' would incorrectly match both; the header's own <h2> is the only
    // level-2 heading on the page, so its closest section unambiguously scopes to just the header.
    const headerSection = (await screen.findByRole('heading', { level: 2 })).closest('section')
    if (!headerSection) throw new Error('expected the Employee 360 header section to be present')
    const header = within(headerSection)
    expect(header.getByText('1234567890')).toBeInTheDocument()
    expect(header.getByText('EMP-001')).toBeInTheDocument()
    expect(await header.findByText('على رأس العمل')).toBeInTheDocument()
    // The resolved unit name appears in both the always-visible header and the default
    // Overview tab (original workplace) — assert presence, not a single exact match.
    expect((await screen.findAllByText('الإدارة العامة للمستشفيات')).length).toBeGreaterThan(0)
  })

  it('shows the Overview tab by default with original and actual workplace', async () => {
    stub360App()
    renderApp(ROUTE)

    await screen.findByRole('heading', { level: 1, name: 'الملف الشامل للموظف' })
    const overviewPanel = await screen.findByRole('tabpanel')
    expect(within(overviewPanel).getByText('مكان العمل الأصلي')).toBeInTheDocument()
    expect(within(overviewPanel).getAllByText('الإدارة العامة للمستشفيات').length).toBeGreaterThan(0)
  })

  it('switches to the Status History tab and lists the status periods', async () => {
    stub360App()
    const user = userEvent.setup()
    renderApp(ROUTE)

    await screen.findByRole('heading', { level: 1, name: 'الملف الشامل للموظف' })
    await user.click(screen.getByRole('tab', { name: 'سجل الحالات الوظيفية' }))

    const table = await screen.findByRole('table')
    expect(within(table).getByText('على رأس العمل')).toBeInTheDocument()
    expect(within(table).getByText('مستمرة')).toBeInTheDocument()
  })

  it('switches to the Movement Timeline tab and shows the placement period, with no fabricated "transfer" entries', async () => {
    stub360App()
    const user = userEvent.setup()
    renderApp(ROUTE)

    await screen.findByRole('heading', { level: 1, name: 'الملف الشامل للموظف' })
    await user.click(screen.getByRole('tab', { name: 'الخط الزمني للحركات' }))

    const table = await screen.findByRole('table')
    expect(within(table).getByText('إلحاق تنظيمي')).toBeInTheDocument()
    expect(within(table).queryByText(/نقل/)).not.toBeInTheDocument()
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
})
