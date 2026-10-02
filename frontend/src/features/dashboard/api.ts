import { apiRequest } from '../../shared/api'

/**
 * Mirrors the backend WorkforceAnalyticsResource (S44, docs/workforce-analytics-foundation-specification.md §S44.11). The S45
 * Dashboard consumes THIS one canonical response for the whole page (DB-D48): it never calls a report endpoint and no widget
 * fetches on its own. The response is aggregate-only: it carries no national id and no name.
 */

/** A share of a partition of OVERALL_HEADCOUNT (single-value dimensions). `basis_points`/`percent` are null for a zero denominator. */
export interface Percentage {
  numerator: number
  denominator_type: 'OVERALL_HEADCOUNT'
  denominator_value: number
  basis_points: number | null
  percent: string | null
}

/** A share of OVERALL_HEADCOUNT exposed to one bucket of a multi-value dimension. Never a partition: shares may sum above 100%. */
export interface ExposureShare extends Percentage {
  semantics: 'SHARE_OF_OVERALL_POPULATION_EXPOSED_TO_BUCKET'
}

export type DutyState = 'HAS_ON_DUTY' | 'NO_ON_DUTY' | 'INDETERMINATE'

export interface SingleValueSection<TBucket> {
  basis: string
  denominator_type: 'OVERALL_HEADCOUNT'
  denominator_value: number
  buckets: TBucket[]
  reconciles_to_overall_headcount: boolean
}

export interface MultiValueBucket {
  bucket: string
  state: string | null
  code?: string | null
  name_ar?: string | null
  name_en?: string | null
  relationship_type?: string
  status?: string
  person_count: number
  relationship_count: number
  exposure_share: ExposureShare
}

export interface MultiValueSection {
  grain: 'MULTI_VALUE_TEMPORAL'
  semantics: 'SHARE_OF_OVERALL_POPULATION_EXPOSED_TO_BUCKET'
  denominator_type: 'OVERALL_HEADCOUNT'
  denominator_value: number
  buckets_reconcile_to_overall_headcount: false
  buckets: MultiValueBucket[]
}

export interface DutyBucket {
  duty_state: DutyState
  person_count: number
  percentage: Percentage
}

export interface GenderBucket {
  gender: string
  name_ar: string | null
  name_en: string | null
  person_count: number
  percentage: Percentage
}

export interface BandBucket {
  band: string
  person_count: number
  percentage: Percentage
}

export interface AgeSection extends SingleValueSection<BandBucket> {
  /** Informational calculation states — NOT official age bands (WA-D69). */
  calculation_states: { state: 'CALCULABLE' | 'NOT_RECORDED' | 'NOT_CALCULABLE'; person_count: number }[]
}

export interface NamedRef {
  id: string
  code: string
  name_ar: string
  name_en: string | null
}

export interface QualificationBucket {
  academic_degree: NamedRef | null
  qualification_type: NamedRef | null
  state: 'PRIMARY' | 'NOT_RECORDED'
  person_count: number
  percentage: Percentage
}

export interface PlacementUnit {
  unit_id: string
  name: string
  parent_id: string | null
  depth: number
  path: string[]
  direct_person_count: number
  subtree_person_count: number
  direct_exposure_share: ExposureShare
  subtree_exposure_share: ExposureShare
}

export interface WorkplaceUnit {
  unit_id: string
  name: string
  path: string[]
  person_count: number
  relationship_count: number
  exposure_share: ExposureShare
  movement_types: { movement_type: string; person_count: number }[]
  allocated_weekdays: { weekday: string; person_count: number }[]
}

export interface DataQualityEntry {
  code: string
  grain: 'PERSON' | 'RELATIONSHIP'
  person_count: number
  relationship_count: number | null
  /** Opaque ids already part of the S44 aggregate contract. The Dashboard never renders them (identity drilldown is out of S45). */
  affected_person_ids: string[]
}

export interface WorkforceAnalytics {
  reporting_month: string
  month_start: string
  next_month_start: string
  month_end: string
  metadata: { semantics: Record<string, string> }
  population: {
    overall_headcount: number
    relationship_count: number
    duty_state: {
      denominator_type: 'OVERALL_HEADCOUNT'
      denominator_value: number
      buckets: DutyBucket[]
      reconciles_to_overall_headcount: boolean
    }
  }
  demographics: { gender: SingleValueSection<GenderBucket>; age: AgeSection }
  employment: {
    relationship_type: MultiValueSection
    employment_category: MultiValueSection
    contract_dimension: { contract_types: MultiValueSection; population_mapping: MultiValueSection }
    service: SingleValueSection<BandBucket>
  }
  qualifications: {
    primary_qualification: SingleValueSection<QualificationBucket>
    specialty: { specialties: MultiValueSection; cadre_mapping: MultiValueSection }
  }
  organization: {
    organizational_placement: {
      semantics: string
      denominator_type: 'OVERALL_HEADCOUNT'
      denominator_value: number
      units: PlacementUnit[]
      not_recorded_person_count: number
      not_recorded_exposure_share: ExposureShare
    }
  }
  actual_work: {
    actual_workplaces: {
      semantics: string
      denominator_type: 'OVERALL_HEADCOUNT'
      denominator_value: number
      allocation_is_not_attendance: true
      workplaces: WorkplaceUnit[]
      non_determinable: { state: string; person_count: number; exposure_share: ExposureShare }[]
    }
  }
  employment_status: { status_exposure: MultiValueSection }
  workforce_flows: {
    relationship_starts: { grain: 'EVENT'; event_count: number; person_count: number }
    relationship_ends: {
      grain: 'EVENT'
      event_date: string
      event_count: number
      in_monthly_population_event_count: number
      event_only_event_count: number
      person_count: number
      by_reason: { reason: string; event_count: number; person_count: number }[]
    }
  }
  data_quality: DataQualityEntry[]
}

/** The one and only request the Dashboard page makes for a selected reporting month (DB-D48/DB-D49). */
export function fetchWorkforceAnalytics(month: string, signal?: AbortSignal): Promise<WorkforceAnalytics> {
  return apiRequest<WorkforceAnalytics>(
    `/hr/workforce-analytics?month=${encodeURIComponent(month)}`,
    signal ? { signal } : {},
  )
}
