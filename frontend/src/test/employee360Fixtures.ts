import { vi } from 'vitest'
import type { CurrentPrincipal } from '../features/auth/api'
import { CURRENT_PRINCIPAL_BODY, jsonResponse } from './render'

/** Every read permission Employee 360 needs; write permissions are added per test. */
export const READ_PERMISSIONS = [
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
]

export const PERSON = {
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

export const RELATIONSHIP = {
  id: 'rel-1',
  person_id: 'person-1',
  employment_type_id: 'et-1',
  employee_number: 'EMP-001',
  employee_number_scheme: 'PERMANENT',
  effective_from: '2020-01-01',
  effective_to: null,
  end_knowledge_state: 'NOT_APPLICABLE',
  ended_terminally: null,
  version: 7,
}

const STATUS_CATALOG = {
  data: [
    { id: 'sd-1', category_id: 'c1', code: 'on_duty', name_ar: 'على رأس العمل', name_en: 'On duty', is_active: true, display_order: 1 },
    { id: 'sd-3', category_id: 'c2', code: 'traveling', name_ar: 'مسافر', name_en: 'Traveling', is_active: true, display_order: 3 },
    { id: 'sd-4', category_id: 'c2', code: 'suspended', name_ar: 'موقوف', name_en: 'Suspended', is_active: true, display_order: 4 },
    { id: 'sd-2', category_id: 'c2', code: 'unpaid_leave', name_ar: 'إجازة بدون راتب', name_en: 'Unpaid leave', is_active: true, display_order: 7 },
    { id: 'sd-9', category_id: 'c3', code: 'retired', name_ar: 'متقاعد', name_en: 'Retired', is_active: true, display_order: 9 },
    { id: 'sd-x1', category_id: 'c9', code: 'wants_to_return', name_ar: 'يرغب بالعودة (قديم)', name_en: 'Wants to return (legacy)', is_active: true, display_order: 20 },
  ],
}

const UNIT = { id: 'unit-1', parent_id: null, name: 'الإدارة العامة للمستشفيات', is_active: true, version: 1 }
const UNIT_NORTH = { id: 'unit-9', parent_id: null, name: 'مستشفى الشمال', is_active: true, version: 1 }

export interface PostRecord {
  url: string
  body: Record<string, unknown>
}

export interface Harness {
  posts: PostRecord[]
  gets: string[]
  getCount(fragment: string): number
}

export interface HarnessOptions {
  permissions?: string[]
  /** S49: permissions to withhold from the default read-permission set (e.g. to test a gated section with that one permission removed, while every other read permission is still granted). */
  excludePermissions?: string[]
  relationship?: Record<string, unknown>
  statusPeriods?: unknown[]
  secondments?: unknown[]
  assignments?: unknown[]
  /** S49: overrides the default empty page for GET .../qualifications/{id}/versions. */
  qualificationVersions?: unknown
  /** S49: overrides the default empty page for GET .../qualifications/primary-history. */
  primaryQualificationHistory?: unknown
  /** Return a response to override the default for a write, or undefined for the default 201. */
  onPost?: (record: PostRecord) => Response | Promise<Response> | undefined
}

/** Installs a URL-aware fetch stub for the whole Employee 360 page and records every write. */
export function install360(options: HarnessOptions = {}): Harness {
  const basePermissions = [...CURRENT_PRINCIPAL_BODY.permissions, ...READ_PERMISSIONS, ...(options.permissions ?? [])]
  const permissions = basePermissions.filter((permission) => !(options.excludePermissions ?? []).includes(permission))
  const principal: CurrentPrincipal = { ...CURRENT_PRINCIPAL_BODY, permissions }
  const relationship = { ...RELATIONSHIP, ...options.relationship }
  const harness: Harness = {
    posts: [],
    gets: [],
    getCount: (fragment) => harness.gets.filter((url) => url.includes(fragment)).length,
  }

  const reads = (url: string): Response => {
    if (url.includes('/auth/csrf-cookie')) return new Response(null, { status: 204 })
    if (url.includes('/auth/me')) return jsonResponse(principal)
    if (url.includes('/return-intention-periods')) return jsonResponse([])
    if (url.includes('/return-intention')) return jsonResponse({ as_of: '2026-10-15', return_intention: null })
    if (url.includes('/effective-status')) {
      return jsonResponse({ as_of: '2026-10-15', status: { status_detail_id: 'sd-1', status_detail_code: 'on_duty', derived: false, period_id: 'sp-1', derived_from_period_id: null, effective_from: '2020-01-01', effective_to: null } })
    }
    if (url.includes('/rel-1/status-periods')) {
      return jsonResponse(options.statusPeriods ?? [{ id: 'sp-1', employment_relationship_id: 'rel-1', status_detail_id: 'sd-1', effective_from: '2020-01-01', effective_to: null, travel_pay_status: null }])
    }
    if (url.includes('/rel-1/placement-periods')) {
      return jsonResponse([{ id: 'pl-1', employment_relationship_id: 'rel-1', organizational_unit_id: 'unit-1', effective_from: '2020-01-01', effective_to: null }])
    }
    if (url.includes('/rel-1/full-secondment-periods')) return jsonResponse(options.secondments ?? [])
    if (url.includes('/rel-1/workplace-assignment-periods')) return jsonResponse(options.assignments ?? [])
    if (url.includes('/rel-1/actual-workplace')) return jsonResponse({ organizational_unit_id: 'unit-1', source: 'placement', since: '2020-01-01' })
    if (url.includes('/rel-1/partial-secondment-periods')) return jsonResponse([])
    if (url.includes('/rel-1/work-schedule-periods')) return jsonResponse([])
    if (/\/rel-1\/employment-(category|contract|job-title|specialty)-periods/.test(url)) return jsonResponse([])
    // S49: both of these contain '/hr/persons/person-1/qualifications' as a PREFIX, so they MUST
    // be checked before the generic branch below -- a plain .includes() there would otherwise
    // swallow them and return the wrong response shape ([] instead of a paginated envelope).
    if (/\/qualifications\/[^/]+\/versions(\?|$)/.test(url)) {
      return jsonResponse(
        options.qualificationVersions ?? { data: [], meta: { current_page: 1, last_page: 1, per_page: 25, total: 0 } },
      )
    }
    if (url.includes('/qualifications/primary-history')) {
      return jsonResponse(
        options.primaryQualificationHistory ?? {
          events: [],
          meta: { current_page: 1, last_page: 1, per_page: 25, total: 0 },
          evidence_completeness: { gaps: [] },
        },
      )
    }
    if (url.includes('/hr/persons/person-1/qualifications')) return jsonResponse([])
    if (url.includes('/reference/employment-status-details')) return jsonResponse(STATUS_CATALOG)
    if (url.includes('/reference/decision-types')) {
      return jsonResponse({
        data: [
          { id: 'dt-transfer', code: 'TRANSFER', name_ar: 'نقل', name_en: 'Transfer', is_active: true },
          { id: 'dt-assign', code: 'ASSIGNMENT', name_ar: 'تكليف', name_en: 'Assignment', is_active: true },
        ],
        meta: { last_page: 1 },
      })
    }
    if (url.includes('/organization/units?')) return jsonResponse({ data: [UNIT, UNIT_NORTH], meta: { last_page: 1 } })
    if (url.includes('/organization/units/unit-1')) return jsonResponse(UNIT)
    if (url.includes('/organization/units/unit-9')) return jsonResponse(UNIT_NORTH)
    if (/\/hr\/persons\/person-1\/employment-relationships$/.test(url)) return jsonResponse([relationship])
    if (/\/hr\/persons\/person-1$/.test(url)) return jsonResponse(PERSON)
    return jsonResponse({ message: 'Not found.' }, 404)
  }

  vi.stubGlobal(
    'fetch',
    vi.fn((input: RequestInfo | URL, init?: RequestInit) => {
      const url = String(input)
      if ((init?.method ?? 'GET') === 'POST' && !url.includes('/auth/')) {
        const record: PostRecord = { url, body: JSON.parse(String(init?.body ?? '{}')) as Record<string, unknown> }
        harness.posts.push(record)
        return Promise.resolve(options.onPost?.(record) ?? jsonResponse({}, 201))
      }
      harness.gets.push(url)
      return Promise.resolve(reads(url))
    }),
  )

  return harness
}
