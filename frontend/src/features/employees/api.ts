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

interface PaginatedResponse<T> {
  data: T[]
}

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
