import { describe, expect, it } from 'vitest'
import { ar } from '../../i18n/messages/ar'
import { DATA_QUALITY_CODES, KPI_CONTRACTS, OFFICIAL_AGE_BANDS, kpiContract } from './contract'
import { defaultReportingMonth, isReportingMonth, monthInputToReportingMonth, reportingMonthToMonthInput } from './month'

describe('Dashboard KPI contract (DB-D05 / DB-D53..D57 / DB-D68..D71)', () => {
  it('declares exactly the supported analytics — no unsupported KPI has an entry', () => {
    expect(KPI_CONTRACTS.map((k) => k.id).sort()).toEqual(
      [
        'actual_workplace', 'age', 'contract_dimension', 'data_quality', 'duty_state', 'employment_category', 'gender', 'organizational_placement', 'overall_headcount',
        'primary_qualification', 'relationship_count', 'relationship_ends', 'relationship_starts', 'relationship_type', 'service', 'specialty', 'status_exposure',
      ].sort(),
    )
    const text = JSON.stringify(KPI_CONTRACTS.map((k) => [k.id, k.source])).toLowerCase()
    for (const unsupported of ['absence', 'attendance', 'leave_utilization', 'vacancy', 'occupancy', 'turnover', 'fte', 'productivity', 'payroll', 'forecast']) {
      expect(text).not.toContain(unsupported)
    }
  })

  it('freezes the display family and the historical semantics of the contract-mandated KPIs', () => {
    expect(kpiContract('overall_headcount').family).toBe('SCALAR')
    for (const id of ['duty_state', 'gender', 'age', 'service', 'primary_qualification'] as const) {
      expect(kpiContract(id).family).toBe('MUTUALLY_EXCLUSIVE_DISTRIBUTION')
      expect(kpiContract(id).denominator).toBe('OVERALL_HEADCOUNT')
    }
    for (const id of ['relationship_type', 'employment_category', 'contract_dimension', 'specialty', 'status_exposure', 'actual_workplace'] as const) {
      expect(kpiContract(id).family).toBe('MULTI_VALUE_EXPOSURE')
    }
    expect(kpiContract('organizational_placement').family).toBe('HIERARCHY')
    expect(kpiContract('relationship_starts').family).toBe('EVENT_COUNT')
    expect(kpiContract('relationship_ends').family).toBe('EVENT_COUNT')
    expect(kpiContract('data_quality').family).toBe('DATA_QUALITY')

    expect(kpiContract('gender').historical).toBe('CURRENT_RECORDED_ON_HISTORICAL_RERUN')
    expect(kpiContract('primary_qualification').historical).toBe('CURRENT_RECORDED_ON_HISTORICAL_RERUN')
    for (const id of ['specialty', 'employment_category', 'contract_dimension', 'organizational_placement', 'actual_workplace', 'status_exposure'] as const) {
      expect(kpiContract(id).historical).toBe('TEMPORAL_EXPOSURE')
    }
    expect(kpiContract('relationship_starts').historical).toBe('EVENT_GRAIN')
    expect(kpiContract('relationship_ends').historical).toBe('EVENT_GRAIN')
  })

  it('classifies Employment Status Exposure as MULTI_VALUE_EXPOSURE and TEMPORAL_EXPOSURE (DB-D81), never an exclusive distribution', () => {
    const status = kpiContract('status_exposure')

    expect(status.family).toBe('MULTI_VALUE_EXPOSURE')
    expect(status.historical).toBe('TEMPORAL_EXPOSURE')
    expect(status.family).not.toBe('MUTUALLY_EXCLUSIVE_DISTRIBUTION')
    expect(status.reconciliation).toContain('a Person may be in several buckets')
  })

  it('gives every KPI a canonical source, a grain, a population and a reconciliation rule', () => {
    for (const kpi of KPI_CONTRACTS) {
      expect(kpi.source).not.toBe('')
      expect(kpi.reconciliation).not.toBe('')
      expect(['PERSON', 'RELATIONSHIP', 'EVENT', 'MIXED']).toContain(kpi.grain)
      expect(['S37_MONTHLY_POPULATION', 'MONTH_EVENTS', 'MIXED']).toContain(kpi.population)
    }
    expect(kpiContract('relationship_ends').population).toBe('MONTH_EVENTS')
  })

  it('uses exactly the nine canonical data-quality codes and the official R1 age bands', () => {
    expect([...DATA_QUALITY_CODES].sort()).toEqual(
      [
        'ACTUAL_WORKPLACE_NOT_DETERMINABLE', 'BIRTH_DATE_AFTER_REPORT_DATE', 'GENDER_NOT_RECORDED', 'INDETERMINATE_STATUS_COVERAGE', 'ORGANIZATIONAL_PLACEMENT_NOT_RECORDED',
        'PRIMARY_QUALIFICATION_REQUIRED', 'RELATIONSHIP_END_REASON_NOT_RECORDED', 'TRAVEL_PAY_STATUS_NOT_RECORDED', 'UNKNOWN_LEGACY_RELATIONSHIP_END',
      ].sort(),
    )
    expect([...OFFICIAL_AGE_BANDS]).toEqual(['<25', '25-34', '35-44', '45-54', '55-64', '65+', 'NOT_RECORDED'])
    expect(OFFICIAL_AGE_BANDS).not.toContain('NOT_CALCULABLE')
    for (const kpi of KPI_CONTRACTS) {
      for (const code of kpi.dqDependency) {
        expect(DATA_QUALITY_CODES).toContain(code)
      }
    }
  })

  it('keeps the Arabic labels free of the forbidden renamings and unsupported KPI names', () => {
    const text = JSON.stringify(ar.dashboard)
    for (const forbidden of ['التعيينات الجديدة', 'دوران الموظفين', 'معدل الدوران', 'نسبة الغياب', 'معدل الحضور', 'استخدام الإجازات', 'معدل الشغل', 'الشواغر']) {
      expect(text).not.toContain(forbidden)
    }
    expect(ar.dashboard.headcountLabel).toBe('إجمالي الأشخاص ضمن القوى العاملة خلال الشهر')
  })
})

describe('reporting month helpers', () => {
  it('uses the explicit first day of the month', () => {
    expect(defaultReportingMonth(new Date('2026-11-15T10:00:00'))).toBe('2026-11-01')
    expect(isReportingMonth('2026-11-01')).toBe(true)
    expect(isReportingMonth('2026-11-15')).toBe(false)
    expect(isReportingMonth('2026-13-01')).toBe(false)
    expect(monthInputToReportingMonth('2026-03')).toBe('2026-03-01')
    expect(monthInputToReportingMonth('')).toBeNull()
    expect(monthInputToReportingMonth('2026-13')).toBeNull()
    expect(reportingMonthToMonthInput('2026-03-01')).toBe('2026-03')
  })
})
