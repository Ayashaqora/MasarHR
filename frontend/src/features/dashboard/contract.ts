/**
 * The S45 Dashboard KPI contract (docs/dashboard-foundation-specification.md §S45.4, DB-D05/DB-D53..D57/DB-D63..D71): every supported
 * analytic declares its canonical source, grain, display family, denominator, historical semantics and reconciliation rule. It is
 * data, not behavior: the page renders from the S44 response and uses this registry only for the notices and the labels' semantics.
 * Nothing here computes a business value, and no unsupported KPI (attendance, absence, leave utilization, vacancy, occupancy,
 * turnover, FTE, productivity, payroll, forecasting) has an entry.
 */

export type DisplayFamily =
  | 'SCALAR'
  | 'MUTUALLY_EXCLUSIVE_DISTRIBUTION'
  | 'MULTI_VALUE_EXPOSURE'
  | 'EVENT_COUNT'
  | 'HIERARCHY'
  | 'DATA_QUALITY'

export type HistoricalSemantics =
  | 'HISTORICAL'
  | 'CURRENT_RECORDED_ON_HISTORICAL_RERUN'
  | 'TEMPORAL_EXPOSURE'
  | 'EVENT_GRAIN'
  | 'NOT_HISTORICALLY_RELIABLE'

export interface KpiContract {
  id: string
  family: DisplayFamily
  /** The canonical S44 response path the KPI is read from. */
  source: string
  grain: 'PERSON' | 'RELATIONSHIP' | 'EVENT' | 'MIXED'
  /** The reporting population: the S37 monthly population unless stated. */
  population: 'S37_MONTHLY_POPULATION' | 'MONTH_EVENTS' | 'MIXED'
  denominator: 'OVERALL_HEADCOUNT' | 'NONE'
  historical: HistoricalSemantics
  reconciliation: string
  /** Data-quality codes whose finding affects this KPI. */
  dqDependency: readonly string[]
}

export const KPI_CONTRACTS = [
  { id: 'overall_headcount', family: 'SCALAR', source: 'population.overall_headcount', grain: 'PERSON', population: 'S37_MONTHLY_POPULATION', denominator: 'NONE', historical: 'HISTORICAL', reconciliation: 'equals HAS_ON_DUTY + NO_ON_DUTY + INDETERMINATE', dqDependency: [] },
  { id: 'relationship_count', family: 'SCALAR', source: 'population.relationship_count', grain: 'RELATIONSHIP', population: 'S37_MONTHLY_POPULATION', denominator: 'NONE', historical: 'HISTORICAL', reconciliation: 'at least overall_headcount; never combined with it', dqDependency: [] },
  { id: 'duty_state', family: 'MUTUALLY_EXCLUSIVE_DISTRIBUTION', source: 'population.duty_state', grain: 'PERSON', population: 'S37_MONTHLY_POPULATION', denominator: 'OVERALL_HEADCOUNT', historical: 'HISTORICAL', reconciliation: 'buckets sum to overall_headcount', dqDependency: ['INDETERMINATE_STATUS_COVERAGE'] },
  { id: 'gender', family: 'MUTUALLY_EXCLUSIVE_DISTRIBUTION', source: 'demographics.gender', grain: 'PERSON', population: 'S37_MONTHLY_POPULATION', denominator: 'OVERALL_HEADCOUNT', historical: 'CURRENT_RECORDED_ON_HISTORICAL_RERUN', reconciliation: 'buckets (incl. NOT_RECORDED) sum to overall_headcount', dqDependency: ['GENDER_NOT_RECORDED'] },
  { id: 'age', family: 'MUTUALLY_EXCLUSIVE_DISTRIBUTION', source: 'demographics.age', grain: 'PERSON', population: 'S37_MONTHLY_POPULATION', denominator: 'OVERALL_HEADCOUNT', historical: 'HISTORICAL', reconciliation: 'official bands (incl. NOT_RECORDED) sum to overall_headcount; calculation states are informational', dqDependency: ['BIRTH_DATE_AFTER_REPORT_DATE'] },
  { id: 'service', family: 'MUTUALLY_EXCLUSIVE_DISTRIBUTION', source: 'employment.service', grain: 'PERSON', population: 'S37_MONTHLY_POPULATION', denominator: 'OVERALL_HEADCOUNT', historical: 'HISTORICAL', reconciliation: 'bands (incl. INCOMPLETE) sum to overall_headcount', dqDependency: ['UNKNOWN_LEGACY_RELATIONSHIP_END', 'TRAVEL_PAY_STATUS_NOT_RECORDED'] },
  { id: 'primary_qualification', family: 'MUTUALLY_EXCLUSIVE_DISTRIBUTION', source: 'qualifications.primary_qualification', grain: 'PERSON', population: 'S37_MONTHLY_POPULATION', denominator: 'OVERALL_HEADCOUNT', historical: 'CURRENT_RECORDED_ON_HISTORICAL_RERUN', reconciliation: 'buckets (incl. NOT_RECORDED) sum to overall_headcount', dqDependency: ['PRIMARY_QUALIFICATION_REQUIRED'] },
  { id: 'specialty', family: 'MULTI_VALUE_EXPOSURE', source: 'qualifications.specialty', grain: 'PERSON', population: 'S37_MONTHLY_POPULATION', denominator: 'OVERALL_HEADCOUNT', historical: 'TEMPORAL_EXPOSURE', reconciliation: 'none: a Person may be in several buckets', dqDependency: [] },
  { id: 'employment_category', family: 'MULTI_VALUE_EXPOSURE', source: 'employment.employment_category', grain: 'PERSON', population: 'S37_MONTHLY_POPULATION', denominator: 'OVERALL_HEADCOUNT', historical: 'TEMPORAL_EXPOSURE', reconciliation: 'none: a Person may be in several buckets', dqDependency: [] },
  { id: 'contract_dimension', family: 'MULTI_VALUE_EXPOSURE', source: 'employment.contract_dimension', grain: 'PERSON', population: 'S37_MONTHLY_POPULATION', denominator: 'OVERALL_HEADCOUNT', historical: 'TEMPORAL_EXPOSURE', reconciliation: 'none: a Person may be in several buckets', dqDependency: [] },
  { id: 'relationship_type', family: 'MULTI_VALUE_EXPOSURE', source: 'employment.relationship_type', grain: 'PERSON', population: 'S37_MONTHLY_POPULATION', denominator: 'OVERALL_HEADCOUNT', historical: 'TEMPORAL_EXPOSURE', reconciliation: 'none; intentionally differs from the R1 selected-relationship type distribution', dqDependency: [] },
  { id: 'organizational_placement', family: 'HIERARCHY', source: 'organization.organizational_placement', grain: 'PERSON', population: 'S37_MONTHLY_POPULATION', denominator: 'OVERALL_HEADCOUNT', historical: 'TEMPORAL_EXPOSURE', reconciliation: 'unit exposures are never summed into a total; subtree counts are distinct Persons', dqDependency: ['ORGANIZATIONAL_PLACEMENT_NOT_RECORDED'] },
  { id: 'actual_workplace', family: 'MULTI_VALUE_EXPOSURE', source: 'actual_work.actual_workplaces', grain: 'PERSON', population: 'S37_MONTHLY_POPULATION', denominator: 'OVERALL_HEADCOUNT', historical: 'TEMPORAL_EXPOSURE', reconciliation: 'none; allocation is not attendance; intentionally differs from R2 (HAS_ON_DUTY population)', dqDependency: ['ACTUAL_WORKPLACE_NOT_DETERMINABLE'] },
  { id: 'status_exposure', family: 'MULTI_VALUE_EXPOSURE', source: 'employment_status.status_exposure', grain: 'PERSON', population: 'S37_MONTHLY_POPULATION', denominator: 'OVERALL_HEADCOUNT', historical: 'TEMPORAL_EXPOSURE', reconciliation: 'none: a Person may be in several buckets', dqDependency: ['INDETERMINATE_STATUS_COVERAGE'] },
  { id: 'relationship_starts', family: 'EVENT_COUNT', source: 'workforce_flows.relationship_starts', grain: 'EVENT', population: 'MONTH_EVENTS', denominator: 'NONE', historical: 'EVENT_GRAIN', reconciliation: 'event count; not hires', dqDependency: [] },
  { id: 'relationship_ends', family: 'EVENT_COUNT', source: 'workforce_flows.relationship_ends', grain: 'EVENT', population: 'MONTH_EVENTS', denominator: 'NONE', historical: 'EVENT_GRAIN', reconciliation: 'event count including event-only month-start ends; not turnover; independent of overall_headcount', dqDependency: ['RELATIONSHIP_END_REASON_NOT_RECORDED'] },
  { id: 'data_quality', family: 'DATA_QUALITY', source: 'data_quality', grain: 'MIXED', population: 'MIXED', denominator: 'NONE', historical: 'HISTORICAL', reconciliation: 'counts per canonical code; never hidden', dqDependency: [] },
] as const satisfies readonly KpiContract[]

export type KpiId = (typeof KPI_CONTRACTS)[number]['id']

export function kpiContract(id: KpiId): KpiContract {
  const found = KPI_CONTRACTS.find((kpi) => kpi.id === id)
  if (!found) {
    throw new Error(`Unknown Dashboard KPI: ${id}`)
  }
  return found
}

/** The canonical S44 data-quality codes the Dashboard may show (no alias, no new code). */
export const DATA_QUALITY_CODES = [
  'INDETERMINATE_STATUS_COVERAGE',
  'UNKNOWN_LEGACY_RELATIONSHIP_END',
  'RELATIONSHIP_END_REASON_NOT_RECORDED',
  'PRIMARY_QUALIFICATION_REQUIRED',
  'BIRTH_DATE_AFTER_REPORT_DATE',
  'TRAVEL_PAY_STATUS_NOT_RECORDED',
  'GENDER_NOT_RECORDED',
  'ORGANIZATIONAL_PLACEMENT_NOT_RECORDED',
  'ACTUAL_WORKPLACE_NOT_DETERMINABLE',
] as const

/** The official age bands — exactly the frozen R1 bands (WA-D69): NOT_CALCULABLE is never one of them. */
export const OFFICIAL_AGE_BANDS = ['<25', '25-34', '35-44', '45-54', '55-64', '65+', 'NOT_RECORDED'] as const
