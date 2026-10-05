import { apiRequest } from '../../../shared/api'

/**
 * S46: typed wrappers for the EXISTING Employee 360 write contracts (no new backend route). Every body
 * mirrors the controller's validated fields exactly; nothing here derives or supplies domain state — the
 * backend remains the authority and the caller refetches the canonical reads after a success.
 */

function base(personId: string, relationshipId: string): string {
  return `/hr/persons/${personId}/employment-relationships/${relationshipId}`
}

function post(path: string, body: unknown): Promise<unknown> {
  return apiRequest<unknown>(path, { method: 'POST', body })
}

export interface RecordStatusBody {
  status_detail_code: string
  effective_from: string
  effective_to?: string
  travel_pay_status?: 'PAID' | 'UNPAID'
}

export function recordStatusPeriod(personId: string, relationshipId: string, body: RecordStatusBody) {
  return post(`${base(personId, relationshipId)}/status-periods`, body)
}

export interface RecordReturnIntentionBody {
  intention: 'WANTS_TO_RETURN' | 'DOES_NOT_WANT_TO_RETURN'
  effective_from: string
  effective_to?: string
}

export function recordReturnIntention(personId: string, relationshipId: string, body: RecordReturnIntentionBody) {
  return post(`${base(personId, relationshipId)}/return-intention-periods`, body)
}

export interface TransferBody {
  organizational_unit_id: string
  effective_from: string
  decision_type_id: string
}

export function transferEmployee(personId: string, relationshipId: string, body: TransferBody) {
  return post(`${base(personId, relationshipId)}/transfer`, body)
}

export interface StartFullSecondmentBody {
  organizational_unit_id: string
  effective_from: string
}

export function startFullSecondment(personId: string, relationshipId: string, body: StartFullSecondmentBody) {
  return post(`${base(personId, relationshipId)}/full-secondment-periods`, body)
}

export interface EndPeriodBody {
  effective_to: string
}

export function endFullSecondment(personId: string, relationshipId: string, body: EndPeriodBody) {
  return post(`${base(personId, relationshipId)}/full-secondment-periods/end`, body)
}

export interface StartWorkplaceAssignmentBody {
  organizational_unit_id: string
  effective_from: string
  decision_type_id: string
}

export function startWorkplaceAssignment(personId: string, relationshipId: string, body: StartWorkplaceAssignmentBody) {
  return post(`${base(personId, relationshipId)}/workplace-assignment-periods`, body)
}

export function endWorkplaceAssignment(personId: string, relationshipId: string, body: EndPeriodBody) {
  return post(`${base(personId, relationshipId)}/workplace-assignment-periods/end`, body)
}

export interface RecordPartialSecondmentBody {
  organizational_unit_id: string
  effective_from: string
  effective_to?: string
  weekdays: string[]
}

export function recordPartialSecondment(personId: string, relationshipId: string, body: RecordPartialSecondmentBody) {
  return post(`${base(personId, relationshipId)}/partial-secondment-periods`, body)
}

export interface RecordWorkScheduleBody {
  effective_from: string
  weekdays: string[]
}

export function recordWorkSchedule(personId: string, relationshipId: string, body: RecordWorkScheduleBody) {
  return post(`${base(personId, relationshipId)}/work-schedule-periods`, body)
}

export interface EndRelationshipBody {
  expected_version: number
  effective_to: string
  is_terminal: boolean
}

export function endEmploymentRelationship(personId: string, relationshipId: string, body: EndRelationshipBody) {
  return post(`${base(personId, relationshipId)}/end`, body)
}

/** A selectable reference/unit option (id + both display names; units carry a single `name`). */
export interface ReferenceOption {
  id: string
  code?: string
  name_ar?: string
  name_en?: string
  name?: string
  is_active?: boolean
}

interface Page<T> {
  data: T[]
  meta?: { last_page?: number }
}

const MAX_PAGES = 20

/** Reads every page of a paginated list (the backend pages at 50), capped so a runaway list cannot loop. */
async function fetchAllPages(path: string, signal?: AbortSignal): Promise<ReferenceOption[]> {
  const rows: ReferenceOption[] = []
  let lastPage = 1
  for (let page = 1; page <= Math.min(lastPage, MAX_PAGES); page += 1) {
    const result = await apiRequest<Page<ReferenceOption>>(`${path}?page=${page}`, signal ? { signal } : {})
    rows.push(...result.data)
    lastPage = result.meta?.last_page ?? 1
  }
  return rows
}

export function fetchDecisionTypes(signal?: AbortSignal) {
  return fetchAllPages('/reference/decision-types', signal)
}

export function fetchOrganizationalUnitOptions(signal?: AbortSignal) {
  return fetchAllPages('/organization/units', signal)
}
