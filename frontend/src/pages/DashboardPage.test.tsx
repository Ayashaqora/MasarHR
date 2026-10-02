import { fireEvent, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it, vi } from 'vitest'
import type { CurrentPrincipal } from '../features/auth/api'
import type { WorkforceAnalytics } from '../features/dashboard/api'
import { pct, populatedAnalytics, zeroAnalytics } from '../test/dashboardFixtures'
import { CURRENT_PRINCIPAL_BODY, jsonResponse, renderApp, stubAppFetch } from '../test/render'

const VIEWER: CurrentPrincipal = {
  ...CURRENT_PRINCIPAL_BODY,
  permissions: [...CURRENT_PRINCIPAL_BODY.permissions, 'hr.workforce_analytics.view'],
}

function stubDashboard(options: { body?: () => Response; principal?: CurrentPrincipal } = {}) {
  return stubAppFetch({
    overrides: (url) => {
      if (url.includes('/auth/me')) {
        return jsonResponse(options.principal ?? VIEWER)
      }
      if (url.includes('/hr/workforce-analytics')) {
        return options.body ? options.body() : jsonResponse(populatedAnalytics())
      }
      return undefined
    },
  })
}

const analyticsCalls = (fetchMock: ReturnType<typeof stubDashboard>) =>
  fetchMock.mock.calls.map((call) => String(call[0])).filter((url) => url.includes('/hr/workforce-analytics'))

async function openDashboard() {
  renderApp('/dashboard')
  return screen.findByRole('heading', { level: 2, name: /القوى العاملة خلال الشهر/ })
}

afterEach(() => {
  vi.useRealTimers()
})

describe('Dashboard page — page-level request architecture', () => {
  it('makes exactly ONE analytics request for the default month and no per-widget or report request', async () => {
    vi.useFakeTimers({ toFake: ['Date'], now: new Date('2026-11-15T10:00:00') })
    const fetchMock = stubDashboard()
    await openDashboard()

    const calls = analyticsCalls(fetchMock)
    expect(calls).toHaveLength(1)
    expect(calls[0]).toContain('/api/v1/hr/workforce-analytics?month=2026-11-01')
    // Every business request the page makes is that one analytics call: it composes no report endpoint (DB-D04/DB-D51).
    const businessUrls = fetchMock.mock.calls.map((call) => String(call[0])).filter((url) => url.includes('/hr/'))
    expect(businessUrls).toHaveLength(1)
  })

  it('requests the selected reporting month as the first day of that month, once per selection', async () => {
    vi.useFakeTimers({ toFake: ['Date'], now: new Date('2026-11-15T10:00:00') })
    const fetchMock = stubDashboard()
    await openDashboard()

    fireEvent.change(screen.getByLabelText('شهر التقرير'), { target: { value: '2026-03' } })

    await waitFor(() => expect(analyticsCalls(fetchMock)).toHaveLength(2))
    expect(analyticsCalls(fetchMock)[1]).toContain('month=2026-03-01')
    expect(await screen.findByTestId('selected-month')).toHaveTextContent('2026-11-01 — 2026-11-30')
  })

  it('shows the loading state, then the canonical data', async () => {
    stubDashboard()
    renderApp('/dashboard')

    expect(await screen.findByText('جارٍ تحميل مؤشرات الشهر…')).toBeInTheDocument()
    expect(await openDashboard()).toBeInTheDocument()
  })
})

describe('Dashboard page — states', () => {
  it('shows an accessible error with a working retry that makes a new single request', async () => {
    let calls = 0
    const fetchMock = stubDashboard({
      body: () => {
        calls += 1
        return calls === 1 ? jsonResponse({ message: 'boom' }, 500) : jsonResponse(populatedAnalytics())
      },
    })
    const user = userEvent.setup()
    renderApp('/dashboard')

    expect(await screen.findByRole('alert')).toHaveTextContent('تعذّر تحميل مؤشرات الشهر')
    await user.click(screen.getByRole('button', { name: 'إعادة المحاولة' }))

    expect(await openDashboard()).toBeInTheDocument()
    expect(analyticsCalls(fetchMock)).toHaveLength(2)
  })

  it('shows the forbidden state when the API answers 403, without a retry loop', async () => {
    const fetchMock = stubDashboard({ body: () => jsonResponse({ message: 'This action is unauthorized.' }, 403) })
    renderApp('/dashboard')

    expect(await screen.findByText('لا تملك صلاحية عرض مؤشرات القوى العاملة')).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'إعادة المحاولة' })).not.toBeInTheDocument()
    expect(analyticsCalls(fetchMock)).toHaveLength(1)
  })

  it('does not request anything when the principal lacks the permission', async () => {
    const fetchMock = stubDashboard({ principal: CURRENT_PRINCIPAL_BODY })
    renderApp('/dashboard')

    expect(await screen.findByText('لا تملك صلاحية الوصول')).toBeInTheDocument()
    expect(analyticsCalls(fetchMock)).toHaveLength(0)
  })

  it('renders a zero population without NaN, Infinity or an invented 0%', async () => {
    stubDashboard({ body: () => jsonResponse(zeroAnalytics()) })
    renderApp('/dashboard')

    expect(await screen.findByTestId('zero-population')).toHaveTextContent('لا توجد علاقات عمل ضمن هذا الشهر')
    expect(screen.getByTestId('value-overall_headcount')).toHaveTextContent('0')
    const text = document.body.textContent ?? ''
    expect(text).not.toMatch(/NaN|Infinity/)
    expect(text).not.toContain('0.00%')
    expect(screen.queryByRole('heading', { level: 2, name: /الجنس/ })).not.toBeInTheDocument()
    // A month-start end is still an event of the month although nobody is in the month's population.
    expect(screen.getByTestId('value-relationship_ends')).toHaveTextContent('1')
    expect(screen.getByTestId('ends-event-only')).toBeInTheDocument()
  })

  it('renders a null percentage as not calculated, never as 0%', async () => {
    const data = populatedAnalytics()
    data.population.duty_state.buckets[0]!.percentage = pct(6, 0)
    stubDashboard({ body: () => jsonResponse(data) })
    renderApp('/dashboard')
    await openDashboard()

    const row = document.querySelector('[data-kpi="duty_state"] [data-bucket="HAS_ON_DUTY"]') as HTMLElement
    expect(within(row).getByText('غير محسوبة')).toBeInTheDocument()
    expect(row.textContent).not.toMatch(/0\.00%|NaN|Infinity/)
  })

  it('rejects an invalid month input without requesting', async () => {
    const fetchMock = stubDashboard()
    await openDashboard()

    fireEvent.change(screen.getByLabelText('شهر التقرير'), { target: { value: '' } })

    expect(await screen.findByRole('alert')).toHaveTextContent('اختر شهراً صالحاً.')
    expect(analyticsCalls(fetchMock)).toHaveLength(1)
  })
})

describe('Dashboard page — semantics', () => {
  it('shows the headcount with its monthly-population meaning, separate from the relationship count', async () => {
    stubDashboard()
    await openDashboard()

    expect(screen.getByText('إجمالي الأشخاص ضمن القوى العاملة خلال الشهر')).toBeInTheDocument()
    expect(screen.getByText(/ليس هذا عدد العاملين في نهاية الشهر/)).toBeInTheDocument()
    expect(screen.getByTestId('value-overall_headcount')).toHaveTextContent('10')
    expect(screen.getByTestId('value-relationship_count')).toHaveTextContent('12')
    expect(screen.getByText('علاقات العمل ضمن الشهر')).toBeInTheDocument()
  })

  it('keeps INDETERMINATE visible and the duty states mutually exclusive and reconciled', async () => {
    stubDashboard()
    await openDashboard()
    const duty = document.querySelector('[data-kpi="duty_state"]') as HTMLElement

    expect(within(duty).getByText('غير محدد')).toBeInTheDocument()
    const counts = Array.from(duty.querySelectorAll('[data-bucket] .distribution__count')).map((n) => Number(n.textContent))
    expect(counts).toEqual([6, 3, 1])
    expect(counts.reduce((a, b) => a + b, 0)).toBe(10)
    expect(duty).toHaveAttribute('data-family', 'MUTUALLY_EXCLUSIVE_DISTRIBUTION')
  })

  it('keeps NOT_RECORDED, NOT_APPLICABLE and UNMAPPED as their own states, never "other"', async () => {
    stubDashboard()
    await openDashboard()

    const gender = document.querySelector('[data-kpi="gender"]') as HTMLElement
    expect(within(gender).getByText('غير مسجّل')).toBeInTheDocument()
    const contract = document.querySelector('[data-kpi="contract_dimension"]') as HTMLElement
    expect(within(contract).getByText('لا ينطبق')).toBeInTheDocument()
    expect(within(contract).getByText('غير مسجّل')).toBeInTheDocument()
    expect(within(contract).getByText('غير مرتبط')).toBeInTheDocument()
    expect(document.body.textContent).not.toMatch(/أخرى|other/i)
  })

  it('shows exactly the official age bands and never NOT_CALCULABLE as a band (WA-D69)', async () => {
    stubDashboard()
    await openDashboard()
    const age = document.querySelector('[data-kpi="age"]') as HTMLElement

    const bands = Array.from(age.querySelectorAll('.distribution__row')).map((row) => row.getAttribute('data-bucket'))
    expect(bands).toEqual(['<25', '25-34', '35-44', '45-54', '55-64', '65+', 'NOT_RECORDED'])
    expect(bands).not.toContain('NOT_CALCULABLE')
    // The calculation state is informational and separate from the band distribution.
    expect(within(screen.getByTestId('age-calculation-states')).getByText(/غير قابل للاحتساب: 1/)).toBeInTheDocument()
  })

  it('presents multi-value exposure as a non-partition: a note, no total, no 100% claim and no pie', async () => {
    const { container } = (stubDashboard(), renderApp('/dashboard'))
    await openDashboard()

    for (const kpi of ['status_exposure', 'relationship_type', 'actual_workplace', 'employment_category']) {
      const section = document.querySelector(`[data-kpi="${kpi}"]`) as HTMLElement
      expect(section.querySelector('.exposure-note')).toHaveTextContent('الفئات ليست تقسيماً حصرياً')
      expect(section.querySelector('[data-family="MUTUALLY_EXCLUSIVE_DISTRIBUTION"]')).toBeNull()
      expect(section.querySelector('[data-family="MULTI_VALUE_EXPOSURE"]')).not.toBeNull()
    }
    const status = document.querySelector('[data-kpi="status_exposure"]') as HTMLElement
    const persons = Array.from(status.querySelectorAll('.distribution__count')).map((n) => Number.parseInt(n.textContent ?? '0', 10))
    expect(persons.reduce((a, b) => a + b, 0)).toBeGreaterThan(10) // the buckets overlap: they are not a partition…
    expect(status.textContent).not.toMatch(/المجموع|الإجمالي|100\.00%|100%/) // …and no total or full-share claim is shown
    expect(container.querySelector('svg, canvas')).toBeNull()
    expect(document.body.textContent).not.toMatch(/donut|pie/i)
  })

  it('labels the relationship events honestly: counts of events, not hires or turnover', async () => {
    stubDashboard()
    await openDashboard()

    expect(screen.getByText('بدايات علاقات العمل')).toBeInTheDocument()
    expect(screen.getByText('نهايات علاقات العمل')).toBeInTheDocument()
    expect(screen.getByTestId('value-relationship_starts')).toHaveTextContent('2')
    expect(screen.getByTestId('value-relationship_ends')).toHaveTextContent('3')
    expect(screen.getByTestId('ends-event-only')).toHaveTextContent('1')
    const flows = document.querySelector('[data-kpi="relationship_starts"]') as HTMLElement
    expect(flows.textContent).not.toMatch(/التعيينات|تعيينات جديدة|دوران|معدل|hire|turnover|attrition/i)
    expect(within(screen.getByTestId('ends-by-reason')).getByText(/استقالة: 2/)).toBeInTheDocument()
  })

  it('exposes aggregate data-quality warnings and never the affected ids', async () => {
    stubDashboard()
    await openDashboard()
    const dq = document.querySelector('[data-kpi="data_quality"]') as HTMLElement

    expect(dq.querySelectorAll('[data-dq]')).toHaveLength(3)
    expect(within(dq).getByText('تغطية الحالة الوظيفية غير محددة')).toBeInTheDocument()
    expect(document.body.textContent).not.toContain('opaque-person-id')
  })

  it('says so when there are no data-quality findings, instead of hiding the panel', async () => {
    const clean = populatedAnalytics()
    clean.data_quality = clean.data_quality.map((e) => ({ ...e, person_count: 0, relationship_count: e.relationship_count === null ? null : 0, affected_person_ids: [] }))
    stubDashboard({ body: () => jsonResponse(clean) })
    renderApp('/dashboard')

    expect(await screen.findByTestId('dq-none')).toBeInTheDocument()
  })

  it('shows allocation (never attendance) for the actual workplace, weekdays included', async () => {
    stubDashboard()
    await openDashboard()
    const workplace = document.querySelector('[data-kpi="actual_workplace"]') as HTMLElement

    expect(workplace).toHaveTextContent('التخصيص ليس حضوراً ولا أياماً مُنجَزة ولا نسبة وقت')
    expect(workplace).toHaveTextContent('الثلاثاء: 1')
    expect(workplace).toHaveTextContent('التموضع الأصلي أثناء إعارة جزئية')
    expect(screen.getByTestId('workplace-non-determinable')).toHaveTextContent('حالة حركة ملتبسة')
  })

  it('keeps organizational placement as a hierarchy that never sums units into a total', async () => {
    stubDashboard()
    await openDashboard()
    const org = document.querySelector('[data-kpi="organizational_placement"]') as HTMLElement

    expect(org.querySelector('[data-family="HIERARCHY"]')).not.toBeNull()
    expect(org.querySelectorAll('[data-unit]')).toHaveLength(2)
    expect(org).toHaveTextContent('الإدارة العامة')
    expect(org).toHaveTextContent('ضمن الفرع: 7')
    expect(screen.getByTestId('placement-not-recorded')).toHaveTextContent('3')
    expect(org.textContent).not.toMatch(/المجموع الكلي|الإجمالي/)
  })

  it('shows no unsupported KPI and no identity', async () => {
    stubDashboard()
    await openDashboard()
    const text = document.body.textContent ?? ''

    for (const unsupported of ['نسبة الغياب', 'معدل الغياب', 'نسبة الحضور', 'معدل الحضور', 'استخدام الإجازات', 'رصيد الإجازات', 'الشواغر', 'معدل الشغل', 'معدل الدوران', 'دوران الموظفين', 'FTE', 'الإنتاجية', 'الرواتب', 'التنبؤ']) {
      expect(text, unsupported).not.toContain(unsupported)
    }
    expect(text).not.toMatch(/الرقم الوطني|national|الاسم الكامل/i)
    // The temporary statuses are status buckets only — not a leave-management feature.
    expect(screen.getByText('إجازة بدون راتب')).toBeInTheDocument()
  })

  it('announces which values are currently recorded and which are exposure over time', async () => {
    stubDashboard()
    await openDashboard()

    expect(document.querySelector('[data-kpi="gender"]')).toHaveAttribute('data-historical', 'CURRENT_RECORDED_ON_HISTORICAL_RERUN')
    expect(document.querySelector('[data-kpi="primary_qualification"]')).toHaveAttribute('data-historical', 'CURRENT_RECORDED_ON_HISTORICAL_RERUN')
    expect(document.querySelector('[data-kpi="specialty"]')).toHaveAttribute('data-historical', 'TEMPORAL_EXPOSURE')
    const statusSection = document.querySelector('section[data-kpi="status_exposure"]')
    expect(statusSection).toHaveAttribute('data-historical', 'TEMPORAL_EXPOSURE')
    expect(statusSection).toHaveAttribute('data-family', 'MULTI_VALUE_EXPOSURE')
    expect(statusSection?.querySelector('[data-family="MUTUALLY_EXCLUSIVE_DISTRIBUTION"]')).toBeNull()
    expect(document.querySelector('section[data-kpi="relationship_starts"]')).toHaveAttribute('data-historical', 'EVENT_GRAIN')
    expect(within(document.querySelector('[data-kpi="gender"]') as HTMLElement).getByText('مسجّل حالياً')).toBeInTheDocument()
  })
})

describe('Dashboard page — navigation, RTL and localization', () => {
  it('is an authenticated area reachable from the navigation, rendered right-to-left', async () => {
    stubDashboard()
    await openDashboard()

    expect(document.documentElement).toHaveAttribute('dir', 'rtl')
    expect(screen.getByRole('link', { name: 'لوحة المؤشرات' })).toHaveAttribute('href', '/dashboard')
  })

  it('renders the English locale left-to-right with the same one-request architecture', async () => {
    const fetchMock = stubDashboard()
    renderApp('/dashboard', 'en')

    expect(await screen.findByRole('heading', { level: 1, name: 'Workforce indicators dashboard' })).toBeInTheDocument()
    expect(await screen.findByText('Total persons in the workforce during the month')).toBeInTheDocument()
    expect(document.documentElement).toHaveAttribute('dir', 'ltr')
    expect(analyticsCalls(fetchMock)).toHaveLength(1)
  })

  it('does not show the dashboard to an unauthenticated visitor', async () => {
    const fetchMock = stubAppFetch()
    renderApp('/dashboard')

    await waitFor(() => expect(screen.queryByText('جارٍ تحميل مؤشرات الشهر…')).not.toBeInTheDocument())
    expect(analyticsCalls(fetchMock as never)).toHaveLength(0)
  })
})

describe('Dashboard payload typing', () => {
  it('uses the S44 response shape the backend resource projects', () => {
    const data: WorkforceAnalytics = populatedAnalytics()

    expect(Object.keys(data).sort()).toEqual(
      ['actual_work', 'data_quality', 'demographics', 'employment', 'employment_status', 'metadata', 'month_end', 'month_start', 'next_month_start', 'organization', 'population', 'qualifications', 'reporting_month', 'workforce_flows'].sort(),
    )
  })
})
