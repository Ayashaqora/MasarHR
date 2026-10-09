import { apiRequest } from '../../shared/api'

/**
 * Mirrors backend PersonResource (S09 spec §4/§19, extended by S24 — docs/person-profile-foundation-
 * specification.md §S24.12). Every S24 profile field is null for a legacy Person whose value was
 * never recorded; the UI must show "not recorded", never a placeholder or a derived value.
 */
export interface Person {
  id: string
  national_id: string
  is_terminal: boolean
  version: number
  full_name_ar: string | null
  gender_id: string | null
  marital_status_id: string | null
  /** A business DATE (YYYY-MM-DD). */
  birth_date: string | null
  birth_place: string | null
}

/** Mirrors backend EmploymentRelationshipResource (S09 spec §6/§19). */
export interface EmploymentRelationship {
  id: string
  person_id: string
  employment_type_id: string
  employee_number: string | null
  /** 'PERMANENT' | 'CONTRACT' — a database-enforced enum, already self-describing (see spec §S18 data contract). */
  employee_number_scheme: 'PERMANENT' | 'CONTRACT'
  effective_from: string | null
  effective_to: string | null
  end_knowledge_state: 'NOT_APPLICABLE' | 'KNOWN' | 'UNKNOWN'
  ended_terminally: boolean | null
  version: number
}

/** Mirrors backend EmploymentStatusPeriodResource (S10 spec §5/§15). */
export interface EmploymentStatusPeriod {
  id: string
  employment_relationship_id: string
  status_detail_id: string
  effective_from: string | null
  effective_to: string | null
  /** S41: meaningful only for the `traveling` status; null when not applicable or not recorded. */
  travel_pay_status: 'PAID' | 'UNPAID' | null
}

/** Shared shape of OrganizationalPlacementPeriodResource/FullSecondmentPeriodResource/WorkplaceAssignmentPeriodResource. */
export interface OrganizationalUnitPeriod {
  id: string
  employment_relationship_id: string
  organizational_unit_id: string
  effective_from: string | null
  effective_to: string | null
}

/** Mirrors backend ActualWorkplaceResource (S12/S16 spec). */
export interface ActualWorkplace {
  organizational_unit_id: string | null
  /** 'secondment' | 'assignment' | 'placement' | null */
  source: 'secondment' | 'assignment' | 'placement' | null
  since: string | null
}

/** Mirrors backend OrganizationalUnitResource (S07 spec §11/§15). */
export interface OrganizationalUnit {
  id: string
  parent_id: string | null
  name: string
  is_active: boolean
  version: number
}

/** Mirrors backend EmploymentStatusDetailResource (S06 spec). */
export interface EmploymentStatusDetail {
  id: string
  category_id: string
  code: string
  name_ar: string
  name_en: string
  is_active: boolean
  display_order: number
}

/**
 * Mirrors backend EffectiveEmploymentStatusResource (S32 spec §S32.4/§S32.7). `status.derived` is true
 * for the read-time on_duty that follows an expired bounded status: it has no persisted period
 * (period_id is null) and is never a row of the status history.
 */
export interface EffectiveEmploymentStatus {
  as_of: string
  status: {
    status_detail_id: string
    status_detail_code: string
    derived: boolean
    period_id: string | null
    derived_from_period_id: string | null
    effective_from: string | null
    effective_to: string | null
  } | null
}

interface RelationshipPeriodBase {
  id: string
  employment_relationship_id: string
  effective_from: string | null
  effective_to: string | null
}

/** Mirrors PartialSecondmentPeriodResource (S30). */
export interface PartialSecondmentPeriod extends RelationshipPeriodBase {
  organizational_unit_id: string
  weekdays: string[]
}

/** Mirrors WorkSchedulePeriodResource (S29). */
export interface WorkSchedulePeriod extends RelationshipPeriodBase {
  weekdays: string[]
}

/** Mirrors EmploymentCategoryPeriodResource (S20). */
export interface EmploymentCategoryPeriod extends RelationshipPeriodBase {
  employment_category_id: string
}

/** Mirrors EmploymentContractPeriodResource (S21). */
export interface EmploymentContractPeriod extends RelationshipPeriodBase {
  contract_type_id: string
  contractual_effective_to: string | null
  contract_end_knowledge_state: 'KNOWN' | 'UNKNOWN_LEGACY'
}

/** Mirrors EmploymentJobTitlePeriodResource (S22). */
export interface EmploymentJobTitlePeriod extends RelationshipPeriodBase {
  job_title_id: string
  start_knowledge_state: 'KNOWN' | 'UNKNOWN_LEGACY'
}

/** Mirrors EmploymentSpecialtyPeriodResource (S26). */
export interface EmploymentSpecialtyPeriod extends RelationshipPeriodBase {
  specialty_id: string
}

/**
 * Mirrors PersonQualificationResource (S23 base shape, widened by S48 —
 * docs/person-qualification-history-foundation-specification.md Sec.S48.4/S48.13 — to surface the
 * qualification's CURRENT version). `obtained_on` is null when never recorded — never inferred from
 * `created_at`, which is the qualification's own recording timestamp, not the date obtained (D02).
 * `provenance` reflects only whether `created_by_principal_id` is known on the current version; it
 * is never proof that the record was created after S48 (D36, verified by a dedicated backend test).
 */
export interface PersonQualification {
  id: string
  person_id: string
  academic_degree_id: string
  qualification_type_id: string
  obtained_on: string | null
  created_at: string
  version_number: number
  provenance: QualificationProvenance
  is_primary: boolean
}

/** 'RECORDED' = the current version carries a known creating actor; 'BACKFILLED_UNKNOWN_ACTOR' = it does not (D36). */
export type QualificationProvenance = 'RECORDED' | 'BACKFILLED_UNKNOWN_ACTOR'

/**
 * Mirrors PersonQualificationVersionResource (S48 Sec.S48.14, D26/D37) — one row of a single
 * qualification's version history, oldest first. `reason` is null only for the qualification's
 * original recorded version (a correction always supplies one); `academic_degree_id`/
 * `qualification_type_id` are nullable because a version may record only one of the two (S23).
 */
export interface PersonQualificationVersion {
  version_number: number
  academic_degree_id: string | null
  qualification_type_id: string | null
  obtained_on: string | null
  reason: string | null
  is_current: boolean
  provenance: QualificationProvenance
  created_by_principal_id: string | null
  created_at: string
}

/** The two, and only two, evidence-gap codes the backend can emit (S48 Sec.S48.10/S48.14) — never invented. */
export type EvidenceGapCode = 'GAP_NO_DESIGNATION_EVIDENCE' | 'GAP_CHAIN_BROKEN'

/** Mirrors the `gaps` entries of `evidence_completeness` (S48 D35) — exactly `code` and `qualification_id`, nothing else. */
export interface EvidenceGap {
  code: EvidenceGapCode
  qualification_id: string
}

/**
 * Mirrors PrimaryQualificationHistoryEventResource (S48 Sec.S48.14). `type` is 'DESIGNATED' (an
 * explicit designate-primary call that changed state) or 'AUTO_FIRST' (the automatic Primary
 * granted to a Person's first-ever recorded qualification) — never a correction, which this
 * history deliberately excludes (D15). `previous_primary_qualification_id` is null exactly when
 * there was no current Primary to replace, which is a legitimately evidenced start of history, not
 * a gap on its own.
 */
export interface PrimaryQualificationHistoryEvent {
  type: 'DESIGNATED' | 'AUTO_FIRST'
  qualification_id: string
  previous_primary_qualification_id: string | null
  actor_principal_id: string | null
  occurred_at: string
}


/** The subset of SimpleReferenceValueResource Employee 360 needs to name a catalog value. */
export interface ReferenceValue {
  id: string
  code: string
  name_ar: string
  name_en: string
}

export type ReferenceSegment =
  | 'employment-categories'
  | 'contract-types'
  | 'job-titles'
  | 'specialties'
  | 'academic-degrees'
  | 'qualification-types'

export type ReturnIntentionValue = 'WANTS_TO_RETURN' | 'DOES_NOT_WANT_TO_RETURN'

/** Mirrors ReturnIntentionPeriodResource (S34): an independent concept, NOT an employment status. */
export interface ReturnIntentionPeriod extends RelationshipPeriodBase {
  intention: ReturnIntentionValue
}

/** Mirrors the S34 effective Return Intention read: `return_intention: null` means NOT RECORDED. */
export interface EffectiveReturnIntention {
  as_of: string
  return_intention: {
    period_id: string
    intention: ReturnIntentionValue
    effective_from: string | null
    effective_to: string | null
  } | null
}

interface PaginatedResponse<T> {
  data: T[]
}

/** Laravel's standard paginated-resource envelope (mirrors followUps/api.ts's FollowUpPage<T>). */
export interface QualificationPage<T> {
  data: T[]
  meta: { current_page: number; last_page: number; per_page: number; total: number }
}

/**
 * Mirrors PersonQualificationController::primaryHistory()'s body exactly (S48 Sec.S48.14, D35):
 * `evidence_completeness` is a SIBLING of the paginated `events` envelope, not nested inside it,
 * and is identical on every page — it is computed once over the whole chain before any page
 * boundary is applied.
 */
export interface PrimaryQualificationHistoryPage {
  events: PrimaryQualificationHistoryEvent[]
  meta: { current_page: number; last_page: number; per_page: number; total: number }
  evidence_completeness: { gaps: EvidenceGap[] }
}

/** Fixed per the spec (Sec.6): page-at-a-time, matching the backend's own default/max (25/100). */
export const QUALIFICATIONS_ARCHIVE_PER_PAGE = 25

/**
 * The only person-discovery capability the backend exposes (S09 spec §19): an exact match on a
 * fully-supplied national ID. There is no partial-name search and no "list all persons" endpoint
 * — Person carries no name field in S09 v1 (spec §S18 discovery §4) — so this is deliberately the
 * whole of the Employees search screen's server call, not a first step toward a broader one.
 */
export function lookupPersonByNationalId(nationalId: string, signal?: AbortSignal): Promise<Person> {
  return apiRequest<Person>(
    `/hr/persons/lookup?national_id=${encodeURIComponent(nationalId)}`,
    signal ? { signal } : {},
  )
}

export function fetchPerson(personId: string, signal?: AbortSignal): Promise<Person> {
  return apiRequest<Person>(`/hr/persons/${personId}`, signal ? { signal } : {})
}

export function fetchEmploymentRelationships(
  personId: string,
  signal?: AbortSignal,
): Promise<EmploymentRelationship[]> {
  return apiRequest<EmploymentRelationship[]>(
    `/hr/persons/${personId}/employment-relationships`,
    signal ? { signal } : {},
  )
}

export function fetchStatusPeriods(
  personId: string,
  relationshipId: string,
  signal?: AbortSignal,
): Promise<EmploymentStatusPeriod[]> {
  return apiRequest<EmploymentStatusPeriod[]>(
    `/hr/persons/${personId}/employment-relationships/${relationshipId}/status-periods`,
    signal ? { signal } : {},
  )
}

export function fetchPlacementPeriods(
  personId: string,
  relationshipId: string,
  signal?: AbortSignal,
): Promise<OrganizationalUnitPeriod[]> {
  return apiRequest<OrganizationalUnitPeriod[]>(
    `/hr/persons/${personId}/employment-relationships/${relationshipId}/placement-periods`,
    signal ? { signal } : {},
  )
}

export function fetchFullSecondmentPeriods(
  personId: string,
  relationshipId: string,
  signal?: AbortSignal,
): Promise<OrganizationalUnitPeriod[]> {
  return apiRequest<OrganizationalUnitPeriod[]>(
    `/hr/persons/${personId}/employment-relationships/${relationshipId}/full-secondment-periods`,
    signal ? { signal } : {},
  )
}

export function fetchWorkplaceAssignmentPeriods(
  personId: string,
  relationshipId: string,
  signal?: AbortSignal,
): Promise<OrganizationalUnitPeriod[]> {
  return apiRequest<OrganizationalUnitPeriod[]>(
    `/hr/persons/${personId}/employment-relationships/${relationshipId}/workplace-assignment-periods`,
    signal ? { signal } : {},
  )
}

export function fetchActualWorkplace(
  personId: string,
  relationshipId: string,
  signal?: AbortSignal,
): Promise<ActualWorkplace> {
  return apiRequest<ActualWorkplace>(
    `/hr/persons/${personId}/employment-relationships/${relationshipId}/actual-workplace`,
    signal ? { signal } : {},
  )
}

export function fetchOrganizationalUnit(unitId: string, signal?: AbortSignal): Promise<OrganizationalUnit> {
  return apiRequest<OrganizationalUnit>(`/organization/units/${unitId}`, signal ? { signal } : {})
}

export async function fetchEmploymentStatusDetails(signal?: AbortSignal): Promise<EmploymentStatusDetail[]> {
  const page = await apiRequest<PaginatedResponse<EmploymentStatusDetail>>(
    '/reference/employment-status-details',
    signal ? { signal } : {},
  )
  return page.data
}

/**
 * S33: the authoritative effective status (S32 semantics) for today's business date. No `as_of` is
 * sent — the backend applies its own business date, so the browser clock is never the authority.
 */
export function fetchEffectiveStatus(
  personId: string,
  relationshipId: string,
  signal?: AbortSignal,
): Promise<EffectiveEmploymentStatus> {
  return apiRequest<EffectiveEmploymentStatus>(
    `/hr/persons/${personId}/employment-relationships/${relationshipId}/effective-status`,
    signal ? { signal } : {},
  )
}

export function fetchRelationshipPeriods<T>(
  personId: string,
  relationshipId: string,
  stream:
    | 'partial-secondment-periods'
    | 'work-schedule-periods'
    | 'employment-category-periods'
    | 'employment-contract-periods'
    | 'employment-job-title-periods'
    | 'employment-specialty-periods',
  signal?: AbortSignal,
): Promise<T[]> {
  return apiRequest<T[]>(
    `/hr/persons/${personId}/employment-relationships/${relationshipId}/${stream}`,
    signal ? { signal } : {},
  )
}

export function fetchPersonQualifications(personId: string, signal?: AbortSignal): Promise<PersonQualification[]> {
  return apiRequest<PersonQualification[]>(`/hr/persons/${personId}/qualifications`, signal ? { signal } : {})
}

/**
 * S48: GET .../qualifications/{id}/versions — every version of this one qualification, oldest
 * first (version_number ascending), exactly as the backend orders them — never re-sorted here.
 */
export function fetchQualificationVersions(
  personId: string,
  qualificationId: string,
  page: number,
  signal?: AbortSignal,
): Promise<QualificationPage<PersonQualificationVersion>> {
  const query = new URLSearchParams({ page: String(page), per_page: String(QUALIFICATIONS_ARCHIVE_PER_PAGE) })
  return apiRequest<QualificationPage<PersonQualificationVersion>>(
    `/hr/persons/${personId}/qualifications/${qualificationId}/versions?${query}`,
    signal ? { signal } : {},
  )
}

/**
 * S48: GET .../qualifications/primary-history — the Person's whole Primary-designation history,
 * in the order the backend returns it — never reordered, and the tie-breaker it applies for equal
 * timestamps is never treated here as proof of true execution order (D41).
 */
export function fetchPrimaryQualificationHistory(
  personId: string,
  page: number,
  signal?: AbortSignal,
): Promise<PrimaryQualificationHistoryPage> {
  const query = new URLSearchParams({ page: String(page), per_page: String(QUALIFICATIONS_ARCHIVE_PER_PAGE) })
  return apiRequest<PrimaryQualificationHistoryPage>(
    `/hr/persons/${personId}/qualifications/primary-history?${query}`,
    signal ? { signal } : {},
  )
}

export function fetchReferenceValue(segment: ReferenceSegment, id: string, signal?: AbortSignal): Promise<ReferenceValue> {
  return apiRequest<ReferenceValue>(`/reference/${segment}/${id}`, signal ? { signal } : {})
}

/** S34: effective Return Intention for today's business date (the backend applies its own clock). */
export function fetchEffectiveReturnIntention(
  personId: string,
  relationshipId: string,
  signal?: AbortSignal,
): Promise<EffectiveReturnIntention> {
  return apiRequest<EffectiveReturnIntention>(
    `/hr/persons/${personId}/employment-relationships/${relationshipId}/return-intention`,
    signal ? { signal } : {},
  )
}

export function fetchReturnIntentionPeriods(
  personId: string,
  relationshipId: string,
  signal?: AbortSignal,
): Promise<ReturnIntentionPeriod[]> {
  return apiRequest<ReturnIntentionPeriod[]>(
    `/hr/persons/${personId}/employment-relationships/${relationshipId}/return-intention-periods`,
    signal ? { signal } : {},
  )
}
