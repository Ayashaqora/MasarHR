import { useCallback, useMemo, useState } from 'react'
import { useApiResource, type ApiResourceState } from '../../shared/hooks/useApiResource'
import {
  fetchActualWorkplace,
  fetchEffectiveReturnIntention,
  fetchEffectiveStatus,
  fetchReturnIntentionPeriods,
  fetchPersonQualifications,
  fetchReferenceValue,
  fetchRelationshipPeriods,
  fetchEmploymentRelationships,
  fetchEmploymentStatusDetails,
  fetchFullSecondmentPeriods,
  fetchOrganizationalUnit,
  fetchPerson,
  fetchPlacementPeriods,
  fetchStatusPeriods,
  fetchWorkplaceAssignmentPeriods,
  lookupPersonByNationalId,
  type ActualWorkplace,
  type EffectiveEmploymentStatus,
  type EffectiveReturnIntention,
  type ReturnIntentionPeriod,
  type EmploymentCategoryPeriod,
  type EmploymentContractPeriod,
  type EmploymentJobTitlePeriod,
  type EmploymentSpecialtyPeriod,
  type PartialSecondmentPeriod,
  type PersonQualification,
  type ReferenceSegment,
  type ReferenceValue,
  type WorkSchedulePeriod,
  type EmploymentRelationship,
  type EmploymentStatusDetail,
  type EmploymentStatusPeriod,
  type OrganizationalUnit,
  type OrganizationalUnitPeriod,
  type Person,
} from './api'

/**
 * Manual (not auto-fetch-on-mount) national-ID search — the Employees screen's only discovery
 * capability (spec §S18 data contract: no partial-name search or "list all persons" endpoint
 * exists). `search` starts a lookup for the given national ID; `clear` returns to the idle state
 * so a stale result from a previously-searched, different national ID never lingers on screen.
 */
export function usePersonSearch(): {
  state: ApiResourceState<Person> | { status: 'idle' }
  search: (nationalId: string) => void
  clear: () => void
} {
  const [query, setQuery] = useState<string | null>(null)
  const fetcher = query === null ? null : (signal: AbortSignal) => lookupPersonByNationalId(query, signal)
  const state = useApiResource(fetcher, [query])

  const search = useCallback((nationalId: string) => setQuery(nationalId), [])
  const clear = useCallback(() => setQuery(null), [])

  return { state: query === null ? { status: 'idle' } : state, search, clear }
}

export function usePerson(personId: string | null): ApiResourceState<Person> & { retry: () => void } {
  const fetcher = personId === null ? null : (signal: AbortSignal) => fetchPerson(personId, signal)
  return useApiResource(fetcher, [personId])
}

export function useEmploymentRelationships(
  personId: string | null,
): ApiResourceState<EmploymentRelationship[]> & { retry: () => void } {
  const fetcher = personId === null ? null : (signal: AbortSignal) => fetchEmploymentRelationships(personId, signal)
  return useApiResource(fetcher, [personId])
}

export function useStatusPeriods(
  personId: string,
  relationshipId: string,
): ApiResourceState<EmploymentStatusPeriod[]> & { retry: () => void } {
  return useApiResource((signal) => fetchStatusPeriods(personId, relationshipId, signal), [personId, relationshipId])
}

export function usePlacementPeriods(
  personId: string,
  relationshipId: string,
): ApiResourceState<OrganizationalUnitPeriod[]> & { retry: () => void } {
  return useApiResource(
    (signal) => fetchPlacementPeriods(personId, relationshipId, signal),
    [personId, relationshipId],
  )
}

export function useFullSecondmentPeriods(
  personId: string,
  relationshipId: string,
): ApiResourceState<OrganizationalUnitPeriod[]> & { retry: () => void } {
  return useApiResource(
    (signal) => fetchFullSecondmentPeriods(personId, relationshipId, signal),
    [personId, relationshipId],
  )
}

export function useWorkplaceAssignmentPeriods(
  personId: string,
  relationshipId: string,
): ApiResourceState<OrganizationalUnitPeriod[]> & { retry: () => void } {
  return useApiResource(
    (signal) => fetchWorkplaceAssignmentPeriods(personId, relationshipId, signal),
    [personId, relationshipId],
  )
}

export function useActualWorkplace(
  personId: string,
  relationshipId: string,
): ApiResourceState<ActualWorkplace> & { retry: () => void } {
  return useApiResource(
    (signal) => fetchActualWorkplace(personId, relationshipId, signal),
    [personId, relationshipId],
  )
}

/** Fetched once and reused everywhere a status_detail_id needs a display name (13 rows total). */
export function useEmploymentStatusDetailCatalog(): ApiResourceState<EmploymentStatusDetail[]> & {
  retry: () => void
} {
  return useApiResource((signal) => fetchEmploymentStatusDetails(signal), [])
}

/**
 * Resolves a set of organizational_unit_id values to their names (S07's OrganizationalUnitResource
 * has no bulk-by-ids endpoint, so each distinct id is fetched individually and deduplicated here —
 * the id lists on an Employee 360 page are small, typically 1-4 distinct units). Backend remains
 * authoritative for every value shown; this hook only fans out reads, it never computes a unit.
 */
export function useOrganizationalUnitNames(unitIds: readonly string[]): {
  status: 'loading' | 'ready'
  names: Record<string, string>
  failedIds: string[]
} {
  const uniqueIds = useMemo(
    () => Array.from(new Set(unitIds.filter((id): id is string => Boolean(id)))).sort(),
    [unitIds],
  )

  const fetcher =
    uniqueIds.length === 0
      ? null
      : async (signal: AbortSignal): Promise<{ units: OrganizationalUnit[]; failedIds: string[] }> => {
          const settled = await Promise.allSettled(uniqueIds.map((id) => fetchOrganizationalUnit(id, signal)))
          const units: OrganizationalUnit[] = []
          const failedIds: string[] = []
          settled.forEach((result, index) => {
            if (result.status === 'fulfilled') {
              units.push(result.value)
            } else {
              failedIds.push(uniqueIds[index]!)
            }
          })
          return { units, failedIds }
        }

  const state = useApiResource(fetcher, uniqueIds)

  if (uniqueIds.length === 0) {
    return { status: 'ready', names: {}, failedIds: [] }
  }

  if (state.status !== 'success') {
    return { status: 'loading', names: {}, failedIds: [] }
  }

  const names: Record<string, string> = {}
  for (const unit of state.data.units) {
    names[unit.id] = unit.name
  }
  return { status: 'ready', names, failedIds: state.data.failedIds }
}

/** S33: the authoritative effective current status (never derived from the persisted rows here). */
export function useEffectiveStatus(
  personId: string,
  relationshipId: string,
): ApiResourceState<EffectiveEmploymentStatus> & { retry: () => void } {
  return useApiResource((signal) => fetchEffectiveStatus(personId, relationshipId, signal), [personId, relationshipId])
}

export function usePartialSecondmentPeriods(personId: string, relationshipId: string) {
  return useApiResource<PartialSecondmentPeriod[]>(
    (signal) => fetchRelationshipPeriods(personId, relationshipId, 'partial-secondment-periods', signal),
    [personId, relationshipId],
  )
}

export function useWorkSchedulePeriods(personId: string, relationshipId: string) {
  return useApiResource<WorkSchedulePeriod[]>(
    (signal) => fetchRelationshipPeriods(personId, relationshipId, 'work-schedule-periods', signal),
    [personId, relationshipId],
  )
}

export function useEmploymentCategoryPeriods(personId: string, relationshipId: string) {
  return useApiResource<EmploymentCategoryPeriod[]>(
    (signal) => fetchRelationshipPeriods(personId, relationshipId, 'employment-category-periods', signal),
    [personId, relationshipId],
  )
}

export function useEmploymentContractPeriods(personId: string, relationshipId: string) {
  return useApiResource<EmploymentContractPeriod[]>(
    (signal) => fetchRelationshipPeriods(personId, relationshipId, 'employment-contract-periods', signal),
    [personId, relationshipId],
  )
}

export function useEmploymentJobTitlePeriods(personId: string, relationshipId: string) {
  return useApiResource<EmploymentJobTitlePeriod[]>(
    (signal) => fetchRelationshipPeriods(personId, relationshipId, 'employment-job-title-periods', signal),
    [personId, relationshipId],
  )
}

export function useEmploymentSpecialtyPeriods(personId: string, relationshipId: string) {
  return useApiResource<EmploymentSpecialtyPeriod[]>(
    (signal) => fetchRelationshipPeriods(personId, relationshipId, 'employment-specialty-periods', signal),
    [personId, relationshipId],
  )
}

export function usePersonQualifications(personId: string): ApiResourceState<PersonQualification[]> & { retry: () => void } {
  return useApiResource((signal) => fetchPersonQualifications(personId, signal), [personId])
}

/**
 * Resolves catalog ids to their reference values one id at a time (the catalogs are paginated at 50
 * rows, so reading a page could miss a value). Same fan-out shape as useOrganizationalUnitNames;
 * an id that cannot be read (e.g. no reference-view permission) is reported in failedIds and the
 * UI falls back to showing the id rather than inventing a name.
 */
export function useReferenceValues(segment: ReferenceSegment, ids: readonly string[]): {
  status: 'loading' | 'ready'
  values: Record<string, ReferenceValue>
} {
  const uniqueIds = useMemo(() => Array.from(new Set(ids.filter(Boolean))).sort(), [ids])

  const fetcher =
    uniqueIds.length === 0
      ? null
      : async (signal: AbortSignal): Promise<ReferenceValue[]> => {
          const settled = await Promise.allSettled(uniqueIds.map((id) => fetchReferenceValue(segment, id, signal)))
          return settled.flatMap((result) => (result.status === 'fulfilled' ? [result.value] : []))
        }

  const state = useApiResource(fetcher, [segment, ...uniqueIds])

  if (uniqueIds.length === 0) return { status: 'ready', values: {} }
  if (state.status !== 'success') {
    // An errored fan-out must not spin forever: fall through to ready with no names.
    return state.status === 'error' ? { status: 'ready', values: {} } : { status: 'loading', values: {} }
  }
  return { status: 'ready', values: Object.fromEntries(state.data.map((value) => [value.id, value])) }
}

/** S34: the effective Return Intention (independent of employment status). */
export function useEffectiveReturnIntention(
  personId: string,
  relationshipId: string,
): ApiResourceState<EffectiveReturnIntention> & { retry: () => void } {
  return useApiResource((signal) => fetchEffectiveReturnIntention(personId, relationshipId, signal), [personId, relationshipId])
}

export function useReturnIntentionPeriods(
  personId: string,
  relationshipId: string,
): ApiResourceState<ReturnIntentionPeriod[]> & { retry: () => void } {
  return useApiResource((signal) => fetchReturnIntentionPeriods(personId, relationshipId, signal), [personId, relationshipId])
}
