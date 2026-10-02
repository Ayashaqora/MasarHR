import { useI18n } from '../../i18n/context'
import type { DataQualityEntry, MultiValueSection, PlacementUnit, WorkforceAnalytics } from './api'
import {
  ageStateLabel,
  bandLabel,
  dataQualityLabel,
  dutyLabel,
  movementLabel,
  multiValueLabel,
  nonDeterminableLabel,
  pickName,
  reasonLabel,
  weekdayLabel,
} from './labels'
import { usePercentText } from './usePercentText'
import {
  DistributionList,
  ExposureList,
  KpiSection,
  ScalarCard,
  type DistributionRow,
  type ExposureRow,
} from './widgets'

/**
 * The Dashboard body: it renders the ONE resolved S44 response (DB-D48). It contains no fetch, no business computation and no
 * identity: every number is read from the canonical response, every label from the localization catalog or a catalog name already
 * in the response.
 */
export function DashboardView({ data }: { data: WorkforceAnalytics }) {
  const { messages, locale } = useI18n()
  const d = messages.dashboard
  const percentText = usePercentText()
  const zero = data.population.overall_headcount === 0

  const multiRows = (section: MultiValueSection): ExposureRow[] =>
    section.buckets.map((bucket) => ({
      key: bucket.bucket,
      label: multiValueLabel(d, locale, bucket),
      personCount: bucket.person_count,
      relationshipCount: bucket.relationship_count,
      share: bucket.exposure_share,
    }))

  const exposure = (section: MultiValueSection) => <ExposureList rows={multiRows(section)} />

  const duty: DistributionRow[] = data.population.duty_state.buckets.map((b) => ({
    key: b.duty_state,
    label: dutyLabel(d, b.duty_state),
    count: b.person_count,
    percentage: b.percentage,
  }))
  const gender: DistributionRow[] = data.demographics.gender.buckets.map((b) => ({
    key: b.gender,
    label: b.gender === 'NOT_RECORDED' ? d.stateNotRecorded : pickName(locale, b.name_ar, b.name_en, b.gender),
    count: b.person_count,
    percentage: b.percentage,
  }))
  const age: DistributionRow[] = data.demographics.age.buckets.map((b) => ({
    key: b.band,
    label: bandLabel(d, b.band),
    count: b.person_count,
    percentage: b.percentage,
  }))
  const service: DistributionRow[] = data.employment.service.buckets.map((b) => ({
    key: b.band,
    label: bandLabel(d, b.band),
    count: b.person_count,
    percentage: b.percentage,
  }))
  const qualification: DistributionRow[] = data.qualifications.primary_qualification.buckets.map((b, index) => ({
    key: `${b.state}-${index}`,
    label:
      b.state === 'NOT_RECORDED'
        ? d.stateNotRecorded
        : [
            b.academic_degree ? pickName(locale, b.academic_degree.name_ar, b.academic_degree.name_en, b.academic_degree.code) : d.qualificationNoDegree,
            b.qualification_type ? pickName(locale, b.qualification_type.name_ar, b.qualification_type.name_en, b.qualification_type.code) : d.qualificationNoType,
          ].join(' — '),
    count: b.person_count,
    percentage: b.percentage,
  }))

  const placement = data.organization.organizational_placement
  const workplaces = data.actual_work.actual_workplaces
  const flows = data.workforce_flows

  return (
    <div className="dashboard" data-reporting-month={data.reporting_month}>
      {zero ? (
        <div className="state-panel state-panel--success" role="status" data-testid="zero-population">
          <p className="state-panel__title">{d.zeroTitle}</p>
          <div className="state-panel__body">{d.zeroDescription}</div>
        </div>
      ) : null}

      <KpiSection kpi="overall_headcount" title={d.populationTitle}>
        <div className="kpi-grid">
          <ScalarCard kpi="overall_headcount" label={d.headcountLabel} value={data.population.overall_headcount} hint={d.headcountHint} />
          <ScalarCard kpi="relationship_count" label={d.relationshipsLabel} value={data.population.relationship_count} hint={d.relationshipsHint} />
        </div>
      </KpiSection>

      {zero ? null : (
        <>
          <KpiSection kpi="duty_state" title={d.dutyTitle} hint={d.dutyHint}>
            <DistributionList rows={duty} />
          </KpiSection>

          <KpiSection kpi="gender" title={d.genderTitle} hint={d.genderHint}>
            <DistributionList rows={gender} />
          </KpiSection>

          <KpiSection kpi="age" title={d.ageTitle} hint={d.ageHint}>
            <DistributionList rows={age} />
            <h3 className="dashboard-section__subtitle">{d.ageCalculationStates}</h3>
            <ul className="plain-list" data-testid="age-calculation-states">
              {data.demographics.age.calculation_states.map((s) => (
                <li key={s.state}>
                  {ageStateLabel(d, s.state)}: {s.person_count}
                </li>
              ))}
            </ul>
          </KpiSection>

          <KpiSection kpi="service" title={d.serviceTitle} hint={d.serviceHint}>
            <DistributionList rows={service} />
          </KpiSection>

          <KpiSection kpi="primary_qualification" title={d.qualificationTitle} hint={d.qualificationHint}>
            <DistributionList rows={qualification} />
          </KpiSection>

          <KpiSection kpi="specialty" title={d.specialtyTitle}>
            {exposure(data.qualifications.specialty.specialties)}
            <h3 className="dashboard-section__subtitle">{d.cadreMappingTitle}</h3>
            {exposure(data.qualifications.specialty.cadre_mapping)}
          </KpiSection>

          <KpiSection kpi="employment_category" title={d.categoryTitle}>
            {exposure(data.employment.employment_category)}
          </KpiSection>

          <KpiSection kpi="contract_dimension" title={d.contractTitle}>
            {exposure(data.employment.contract_dimension.contract_types)}
            <h3 className="dashboard-section__subtitle">{d.contractMappingTitle}</h3>
            {exposure(data.employment.contract_dimension.population_mapping)}
          </KpiSection>

          <KpiSection kpi="relationship_type" title={d.relationshipTypeTitle}>
            {exposure(data.employment.relationship_type)}
          </KpiSection>

          <KpiSection kpi="organizational_placement" title={d.organizationTitle} hint={d.organizationHint}>
            <p className="exposure-note" role="note">
              {d.exposureNote}
            </p>
            <ul className="hierarchy" data-family="HIERARCHY">
              {placement.units.map((unit: PlacementUnit) => (
                <li
                  key={unit.unit_id}
                  className="hierarchy__row"
                  data-unit={unit.unit_id}
                  style={{ paddingInlineStart: `${unit.depth * 1.25}rem` }}
                >
                  <span className="hierarchy__name">{unit.name}</span>
                  <span>
                    {d.organizationDirect}: {unit.direct_person_count} ({percentText(unit.direct_exposure_share)})
                  </span>
                  <span>
                    {d.organizationSubtree}: {unit.subtree_person_count} ({percentText(unit.subtree_exposure_share)})
                  </span>
                </li>
              ))}
            </ul>
            <p className="dashboard-section__hint" data-testid="placement-not-recorded">
              {d.organizationNotRecorded}: {placement.not_recorded_person_count} ({percentText(placement.not_recorded_exposure_share)})
            </p>
          </KpiSection>

          <KpiSection kpi="actual_workplace" title={d.workplaceTitle} hint={d.workplaceHint}>
            <ExposureList
              rows={workplaces.workplaces.map((w) => ({
                key: w.unit_id,
                label: w.name,
                personCount: w.person_count,
                relationshipCount: w.relationship_count,
                share: w.exposure_share,
                detail: (
                  <>
                    {w.movement_types.map((m) => `${movementLabel(d, m.movement_type)}: ${m.person_count}`).join(' · ')}
                    {w.allocated_weekdays.length > 0
                      ? ` — ${w.allocated_weekdays.map((x) => `${weekdayLabel(d, x.weekday)}: ${x.person_count}`).join(' · ')}`
                      : ''}
                  </>
                ),
              }))}
            />
            {workplaces.non_determinable.length > 0 ? (
              <ul className="plain-list" data-testid="workplace-non-determinable">
                {workplaces.non_determinable.map((n) => (
                  <li key={n.state}>
                    {d.workplaceNonDeterminable} — {nonDeterminableLabel(d, n.state)}: {n.person_count} ({percentText(n.exposure_share)})
                  </li>
                ))}
              </ul>
            ) : null}
          </KpiSection>

          <KpiSection kpi="status_exposure" title={d.statusTitle}>
            {exposure(data.employment_status.status_exposure)}
          </KpiSection>
        </>
      )}

      <KpiSection kpi="relationship_starts" title={d.flowsTitle}>
        <div className="kpi-grid">
          <ScalarCard kpi="relationship_starts" label={d.startsLabel} value={flows.relationship_starts.event_count} hint={d.startsHint} />
          <ScalarCard kpi="relationship_ends" label={d.endsLabel} value={flows.relationship_ends.event_count} hint={d.endsHint} />
        </div>
        {flows.relationship_ends.event_only_event_count > 0 ? (
          <p className="dashboard-section__hint" data-testid="ends-event-only">
            {d.endsEventOnly}: {flows.relationship_ends.event_only_event_count}
          </p>
        ) : null}
        {flows.relationship_ends.by_reason.length > 0 ? (
          <>
            <h3 className="dashboard-section__subtitle">{d.endsByReason}</h3>
            <ul className="plain-list" data-testid="ends-by-reason">
              {flows.relationship_ends.by_reason.map((r) => (
                <li key={r.reason}>
                  {reasonLabel(d, r.reason)}: {r.event_count}
                </li>
              ))}
            </ul>
          </>
        ) : null}
      </KpiSection>

      <DataQualityPanel entries={data.data_quality} />
    </div>
  )
}

/** The DATA_QUALITY family: counts per canonical code, shown as warnings — never hidden to make the figures look complete. */
function DataQualityPanel({ entries }: { entries: DataQualityEntry[] }) {
  const { messages } = useI18n()
  const d = messages.dashboard
  const findings = entries.filter((entry) => entry.person_count > 0)

  return (
    <KpiSection kpi="data_quality" title={d.dataQualityTitle} hint={d.dataQualityHint}>
      {findings.length === 0 ? (
        <p data-testid="dq-none">{d.dataQualityNone}</p>
      ) : (
        <ul className="dq-list" role="list">
          {findings.map((entry) => (
            <li key={entry.code} className="dq-list__item" data-dq={entry.code}>
              <span className="dq-list__label">{dataQualityLabel(d, entry.code)}</span>
              <span>
                {entry.person_count} {d.persons}
                {entry.relationship_count !== null ? ` · ${entry.relationship_count} ${d.relationships}` : ''}
              </span>
            </li>
          ))}
        </ul>
      )}
    </KpiSection>
  )
}
