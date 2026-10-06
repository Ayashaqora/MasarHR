import { apiRequest } from '../../shared/api'

/**
 * S47 Expiry Follow-up Read UI (docs/expiry-followups-ui-specification.md §2). Mirrors the backend
 * MovementExpiryFollowUpResource / EmploymentStatusExpiryFollowUpResource exactly (field order and
 * names verified against the Feature tests that pin them) — ids, dates and stable codes only. No
 * employee name, no national id, no organizational unit name: §4/§5.5 of the spec document the
 * verified absence of any permitted, bounded method to resolve either, so none is attempted here.
 */

export type FollowUpState = 'ACTIONABLE' | 'LAPSED' | 'SUPPRESSED' | 'ALL'

/** The persisted `state` filter value, as the backend validates it (spec §2.3/§2.4). */
export type FollowUpStateFilter = FollowUpState

/** The derived, read-only `state` a row is shown in (spec §2.6) — never `ALL`, which is a filter value only. */
export type FollowUpRowState = 'ACTIONABLE' | 'LAPSED' | 'SUPPRESSED'

export type MovementType = 'FULL_SECONDMENT' | 'WORKPLACE_ASSIGNMENT' | 'PARTIAL_SECONDMENT'

/** `FollowUpSuppressionReason` (backend, movement-only — spec §2.7). */
export type MovementSuppressionReason = 'TRUNCATED_EARLIER' | 'END_DATE_CHANGED' | 'RELATIONSHIP_ENDED' | 'COVERED_BY_NEWER_MOVEMENT'

/** `StatusFollowUpSuppressionReason` (backend, status-only — a different set, spec §2.7). */
export type StatusSuppressionReason = 'RELATIONSHIP_ENDED' | 'TRUNCATED_EARLIER' | 'SUCCESSOR_RECORDED'

/** `MovementExpiryFollowUpResource::toArray()` — exact key order verified in the Feature test (spec §2.6). */
export interface MovementExpiryFollowUp {
  id: string
  followup_kind: string
  movement_type: MovementType
  movement_id: string
  employment_relationship_id: string
  organizational_unit_id: string
  expected_effective_to: string
  due_date: string
  status: 'ACTIONABLE' | 'SUPPRESSED'
  state: FollowUpRowState
  suppression_reason: MovementSuppressionReason | null
  created_at: string | null
  suppressed_at: string | null
}

/** `EmploymentStatusExpiryFollowUpResource::toArray()` — exact key order verified in the Feature test (spec §2.6). */
export interface EmploymentStatusExpiryFollowUp {
  id: string
  followup_kind: string
  employment_status_period_id: string
  employment_relationship_id: string
  expected_effective_to: string
  due_date: string
  status: 'ACTIONABLE' | 'SUPPRESSED'
  state: FollowUpRowState
  suppression_reason: StatusSuppressionReason | null
  created_at: string | null
  suppressed_at: string | null
}

/** Laravel's standard paginated-resource envelope (verified against the Feature tests' `meta.total` / `data` reads). */
export interface FollowUpPage<T> {
  data: T[]
  meta: { current_page: number; last_page: number; per_page: number; total: number }
}

/** Fixed per the spec (§2.5/§5.6): page-at-a-time, never "load all pages" — the feed is not a bounded catalog. */
export const FOLLOW_UPS_PER_PAGE = 50

export function fetchMovementExpiryFollowUps(
  params: { state: FollowUpStateFilter; page: number },
  signal?: AbortSignal,
): Promise<FollowUpPage<MovementExpiryFollowUp>> {
  const query = new URLSearchParams({ state: params.state, page: String(params.page), per_page: String(FOLLOW_UPS_PER_PAGE) })
  return apiRequest<FollowUpPage<MovementExpiryFollowUp>>(`/hr/movement-expiry-followups?${query}`, signal ? { signal } : {})
}

export function fetchEmploymentStatusExpiryFollowUps(
  params: { state: FollowUpStateFilter; page: number },
  signal?: AbortSignal,
): Promise<FollowUpPage<EmploymentStatusExpiryFollowUp>> {
  const query = new URLSearchParams({ state: params.state, page: String(params.page), per_page: String(FOLLOW_UPS_PER_PAGE) })
  return apiRequest<FollowUpPage<EmploymentStatusExpiryFollowUp>>(`/hr/employment-status-expiry-followups?${query}`, signal ? { signal } : {})
}
