import type { ExposureShare, MultiValueBucket, MultiValueSection, Percentage, WorkforceAnalytics } from '../features/dashboard/api'

/** Test-only builders that mirror the S44 response shape (they compute nothing the backend would not already have computed). */
export function pct(numerator: number, denominator: number): Percentage {
  const basisPoints = denominator === 0 ? null : Math.floor((2 * numerator * 10000 + denominator) / (2 * denominator))
  return {
    numerator,
    denominator_type: 'OVERALL_HEADCOUNT',
    denominator_value: denominator,
    basis_points: basisPoints,
    percent: basisPoints === null ? null : `${Math.floor(basisPoints / 100)}.${String(basisPoints % 100).padStart(2, '0')}`,
  }
}

export function share(numerator: number, denominator: number): ExposureShare {
  return { semantics: 'SHARE_OF_OVERALL_POPULATION_EXPOSED_TO_BUCKET', ...pct(numerator, denominator) }
}

export function multi(overall: number, buckets: Omit<MultiValueBucket, 'exposure_share' | 'relationship_count'>[]): MultiValueSection {
  return {
    grain: 'MULTI_VALUE_TEMPORAL',
    semantics: 'SHARE_OF_OVERALL_POPULATION_EXPOSED_TO_BUCKET',
    denominator_type: 'OVERALL_HEADCOUNT',
    denominator_value: overall,
    buckets_reconcile_to_overall_headcount: false,
    buckets: buckets.map((b) => ({ ...b, relationship_count: b.person_count, exposure_share: share(b.person_count, overall) })),
  }
}

const DQ_CODES = [
  ['INDETERMINATE_STATUS_COVERAGE', 'RELATIONSHIP'],
  ['UNKNOWN_LEGACY_RELATIONSHIP_END', 'PERSON'],
  ['RELATIONSHIP_END_REASON_NOT_RECORDED', 'RELATIONSHIP'],
  ['PRIMARY_QUALIFICATION_REQUIRED', 'PERSON'],
  ['BIRTH_DATE_AFTER_REPORT_DATE', 'PERSON'],
  ['TRAVEL_PAY_STATUS_NOT_RECORDED', 'PERSON'],
  ['GENDER_NOT_RECORDED', 'PERSON'],
  ['ORGANIZATIONAL_PLACEMENT_NOT_RECORDED', 'RELATIONSHIP'],
  ['ACTUAL_WORKPLACE_NOT_DETERMINABLE', 'RELATIONSHIP'],
] as const

/** A populated 10-person month with every supported section. */
export function populatedAnalytics(): WorkforceAnalytics {
  const n = 10
  const emptyMulti = multi(n, [])
  return {
    reporting_month: '2026-11-01',
    month_start: '2026-11-01',
    next_month_start: '2026-12-01',
    month_end: '2026-11-30',
    metadata: { semantics: {} },
    population: {
      overall_headcount: n,
      relationship_count: 12,
      duty_state: {
        denominator_type: 'OVERALL_HEADCOUNT',
        denominator_value: n,
        reconciles_to_overall_headcount: true,
        buckets: [
          { duty_state: 'HAS_ON_DUTY', person_count: 6, percentage: pct(6, n) },
          { duty_state: 'NO_ON_DUTY', person_count: 3, percentage: pct(3, n) },
          { duty_state: 'INDETERMINATE', person_count: 1, percentage: pct(1, n) },
        ],
      },
    },
    demographics: {
      gender: {
        basis: 'CURRENT_RECORDED',
        denominator_type: 'OVERALL_HEADCOUNT',
        denominator_value: n,
        reconciles_to_overall_headcount: true,
        buckets: [
          { gender: 'FEMALE', name_ar: 'أنثى', name_en: 'Female', person_count: 4, percentage: pct(4, n) },
          { gender: 'MALE', name_ar: 'ذكر', name_en: 'Male', person_count: 5, percentage: pct(5, n) },
          { gender: 'NOT_RECORDED', name_ar: null, name_en: null, person_count: 1, percentage: pct(1, n) },
        ],
      },
      age: {
        basis: 'COMPLETED_YEARS_AT_MONTH_END',
        denominator_type: 'OVERALL_HEADCOUNT',
        denominator_value: n,
        reconciles_to_overall_headcount: true,
        buckets: [
          ['<25', 1], ['25-34', 3], ['35-44', 3], ['45-54', 1], ['55-64', 0], ['65+', 0], ['NOT_RECORDED', 2],
        ].map(([band, count]) => ({ band: band as string, person_count: count as number, percentage: pct(count as number, n) })),
        calculation_states: [
          { state: 'CALCULABLE', person_count: 8 },
          { state: 'NOT_RECORDED', person_count: 1 },
          { state: 'NOT_CALCULABLE', person_count: 1 },
        ],
      },
    },
    employment: {
      relationship_type: multi(n, [
        { bucket: 'permanent', state: null, relationship_type: 'permanent', name_ar: 'دائم', name_en: 'Permanent', person_count: 7 },
        { bucket: 'contract', state: null, relationship_type: 'contract', name_ar: 'متعاقد', name_en: 'Contract', person_count: 5 },
      ]),
      employment_category: multi(n, [
        { bucket: 'grade_1', state: 'RESOLVED', code: 'grade_1', name_ar: 'الأولى', name_en: null, person_count: 4 },
        { bucket: 'NOT_RECORDED', state: 'NOT_RECORDED', person_count: 6 },
      ]),
      contract_dimension: {
        contract_types: multi(n, [
          { bucket: 'NOT_APPLICABLE', state: 'NOT_APPLICABLE', person_count: 6 },
          { bucket: 'NOT_RECORDED', state: 'NOT_RECORDED', person_count: 2 },
        ]),
        population_mapping: multi(n, [{ bucket: 'UNMAPPED', state: 'UNMAPPED', person_count: 2 }]),
      },
      service: {
        basis: 'PERSON_CUMULATIVE_SERVICE',
        denominator_type: 'OVERALL_HEADCOUNT',
        denominator_value: n,
        reconciles_to_overall_headcount: true,
        buckets: [['<5', 6], ['5-9', 3], ['INCOMPLETE', 1]].map(([band, count]) => ({ band: band as string, person_count: count as number, percentage: pct(count as number, n) })),
      },
    },
    qualifications: {
      primary_qualification: {
        basis: 'CURRENT_RECORDED_PRIMARY',
        denominator_type: 'OVERALL_HEADCOUNT',
        denominator_value: n,
        reconciles_to_overall_headcount: true,
        buckets: [
          { academic_degree: { id: 'a', code: 'bachelor', name_ar: 'بكالوريوس', name_en: 'Bachelor' }, qualification_type: null, state: 'PRIMARY', person_count: 4, percentage: pct(4, n) },
          { academic_degree: null, qualification_type: null, state: 'NOT_RECORDED', person_count: 6, percentage: pct(6, n) },
        ],
      },
      specialty: { specialties: emptyMulti, cadre_mapping: multi(n, [{ bucket: 'UNMAPPED', state: 'UNMAPPED', person_count: 1 }]) },
    },
    organization: {
      organizational_placement: {
        semantics: 'SHARE_OF_OVERALL_POPULATION_EXPOSED_TO_BUCKET',
        denominator_type: 'OVERALL_HEADCOUNT',
        denominator_value: n,
        units: [
          { unit_id: 'u-root', name: 'الإدارة العامة', parent_id: null, depth: 0, path: ['u-root'], direct_person_count: 0, subtree_person_count: 7, direct_exposure_share: share(0, n), subtree_exposure_share: share(7, n) },
          { unit_id: 'u-a', name: 'قسم أ', parent_id: 'u-root', depth: 1, path: ['u-root', 'u-a'], direct_person_count: 7, subtree_person_count: 7, direct_exposure_share: share(7, n), subtree_exposure_share: share(7, n) },
        ],
        not_recorded_person_count: 3,
        not_recorded_exposure_share: share(3, n),
      },
    },
    actual_work: {
      actual_workplaces: {
        semantics: 'SHARE_OF_OVERALL_POPULATION_EXPOSED_TO_BUCKET',
        denominator_type: 'OVERALL_HEADCOUNT',
        denominator_value: n,
        allocation_is_not_attendance: true,
        workplaces: [
          {
            unit_id: 'u-a', name: 'قسم أ', path: ['u-root', 'u-a'], person_count: 7, relationship_count: 7, exposure_share: share(7, n),
            movement_types: [{ movement_type: 'PLACEMENT', person_count: 6 }, { movement_type: 'PLACEMENT_UNDERLYING_OF_PARTIAL_ALLOCATION', person_count: 1 }],
            allocated_weekdays: [],
          },
          {
            unit_id: 'u-b', name: 'قسم ب', path: ['u-root', 'u-b'], person_count: 1, relationship_count: 1, exposure_share: share(1, n),
            movement_types: [{ movement_type: 'PARTIAL_SECONDMENT', person_count: 1 }],
            allocated_weekdays: [{ weekday: 'TUESDAY', person_count: 1 }, { weekday: 'SUNDAY', person_count: 1 }],
          },
        ],
        non_determinable: [{ state: 'AMBIGUOUS_MOVEMENT_STATE', person_count: 1, exposure_share: share(1, n) }],
      },
    },
    employment_status: {
      status_exposure: multi(n, [
        { bucket: 'ON_DUTY', state: null, status: 'ON_DUTY', person_count: 8 },
        { bucket: 'TRAVELING', state: null, status: 'TRAVELING', person_count: 2 },
        { bucket: 'CAPTIVE', state: null, status: 'CAPTIVE', person_count: 0 },
        { bucket: 'SUSPENDED', state: null, status: 'SUSPENDED', person_count: 0 },
        { bucket: 'UNPAID_LEAVE', state: null, status: 'UNPAID_LEAVE', person_count: 1 },
        { bucket: 'EXTERNAL_SICK_LEAVE', state: null, status: 'EXTERNAL_SICK_LEAVE', person_count: 0 },
        { bucket: 'INDETERMINATE', state: null, status: 'INDETERMINATE', person_count: 1 },
      ]),
    },
    workforce_flows: {
      relationship_starts: { grain: 'EVENT', event_count: 2, person_count: 2 },
      relationship_ends: {
        grain: 'EVENT', event_date: 'RELATIONSHIP_EFFECTIVE_TO', event_count: 3, in_monthly_population_event_count: 2, event_only_event_count: 1, person_count: 3,
        by_reason: [{ reason: 'RESIGNED', event_count: 2, person_count: 2 }, { reason: 'NOT_RECORDED', event_count: 1, person_count: 1 }],
      },
    },
    data_quality: DQ_CODES.map(([code, grain], index) => ({
      code, grain, person_count: index < 3 ? index + 1 : 0, relationship_count: grain === 'RELATIONSHIP' ? (index < 3 ? index + 1 : 0) : null,
      affected_person_ids: index < 3 ? [`opaque-person-id-${index}`] : [],
    })),
  }
}

/** A month with no persons: every share is null, and a month-start end may still be an event. */
export function zeroAnalytics(): WorkforceAnalytics {
  const base = populatedAnalytics()
  const zeroPct = (n: number) => pct(n, 0)
  return {
    ...base,
    population: { overall_headcount: 0, relationship_count: 0, duty_state: { ...base.population.duty_state, denominator_value: 0, buckets: base.population.duty_state.buckets.map((b) => ({ ...b, person_count: 0, percentage: zeroPct(0) })) } },
    workforce_flows: {
      relationship_starts: { grain: 'EVENT', event_count: 0, person_count: 0 },
      relationship_ends: { grain: 'EVENT', event_date: 'RELATIONSHIP_EFFECTIVE_TO', event_count: 1, in_monthly_population_event_count: 0, event_only_event_count: 1, person_count: 1, by_reason: [{ reason: 'RETIRED', event_count: 1, person_count: 1 }] },
    },
    data_quality: base.data_quality.map((e) => ({ ...e, person_count: 0, relationship_count: e.relationship_count === null ? null : 0, affected_person_ids: [] })),
  }
}
