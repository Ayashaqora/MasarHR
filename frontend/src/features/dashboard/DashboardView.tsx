import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert'
import { CircleCheck } from 'lucide-react'
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
  CountList,
  DistributionList,
  ExposureList,
  ExposureNote,
  KpiSection,
  QualityFinding,
  ScalarCard,
  SubHeading,
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
    <div className="space-y-6" data-reporting-month={data.reporting_month}>
      {zero ? (
        <Alert role="status" data-testid="zero-population" className="border-status-information-border bg-status-information text-status-information-foreground">
          <CircleCheck aria-hidden="true" />
          <AlertTitle>{d.zeroTitle}</AlertTitle>
          <AlertDescription className="text-status-information-foreground">{d.zeroDescription}</AlertDescription>
        </Alert>
      ) : null}

      <KpiSection kpi="overall_headcount" title={d.populationTitle}>
        <div className="grid gap-4 sm:grid-cols-2">
          <ScalarCard kpi="overall_headcount" label={d.headcountLabel} value={data.population.overall_headcount} hint={d.headcountHint} />
          <ScalarCard kpi="relationship_count" label={d.relationshipsLabel} value={data.population.relationship_count} hint={d.relationshipsHint} />
        </div>
      </KpiSection>

      {zero ? null : (
        <div className="grid gap-4 lg:grid-cols-2">
          <KpiSection kpi="duty_state" title={d.dutyTitle} hint={d.dutyHint}>
            <DistributionList rows={duty} />
          </KpiSection>

          <KpiSection kpi="gender" title={d.genderTitle} hint={d.genderHint}>
            <DistributionList rows={gender} />
          </KpiSection>

          <KpiSection kpi="age" title={d.ageTitle} hint={d.ageHint}>
            <DistributionList rows={age} />
            <SubHeading>{d.ageCalculationStates}</SubHeading>
            <CountList
              testId="age-calculation-states"
              items={data.demographics.age.calculation_states.map((s) => ({
                key: s.state,
                text: (
                  <>
                    {ageStateLabel(d, s.state)}: {s.person_count}
                  </>
                ),
              }))}
            />
          </KpiSection>

          <KpiSection kpi="service" title={d.serviceTitle} hint={d.serviceHint}>
            <DistributionList rows={service} />
          </KpiSection>

          <KpiSection kpi="primary_qualification" title={d.qualificationTitle} hint={d.qualificationHint} wide>
            <DistributionList rows={qualification} />
          </KpiSection>

          <KpiSection kpi="specialty" title={d.specialtyTitle}>
            {exposure(data.qualifications.specialty.specialties)}
            <SubHeading>{d.cadreMappingTitle}</SubHeading>
            {exposure(data.qualifications.specialty.cadre_mapping)}
          </KpiSection>

          <KpiSection kpi="employment_category" title={d.categoryTitle}>
            {exposure(data.employment.employment_category)}
          </KpiSection>

          <KpiSection kpi="contract_dimension" title={d.contractTitle}>
            {exposure(data.employment.contract_dimension.contract_types)}
            <SubHeading>{d.contractMappingTitle}</SubHeading>
            {exposure(data.employment.contract_dimension.population_mapping)}
          </KpiSection>

          <KpiSection kpi="relationship_type" title={d.relationshipTypeTitle}>
            {exposure(data.employment.relationship_type)}
          </KpiSection>

          <KpiSection kpi="organizational_placement" title={d.organizationTitle} hint={d.organizationHint} wide>
            <ExposureNote />
            <ul className="divide-y rounded-md border" data-family="HIERARCHY">
              {placement.units.map((unit: PlacementUnit) => (
                <li
                  key={unit.unit_id}
                  className="grid gap-x-4 gap-y-1 px-3 py-2 text-sm sm:grid-cols-[minmax(0,1fr)_auto_auto] sm:items-baseline"
                  data-unit={unit.unit_id}
                  style={{ paddingInlineStart: `${0.75 + unit.depth * 1.25}rem` }}
                >
                  <span className="font-medium">{unit.name}</span>
                  <span className="tabular-nums text-muted-foreground">
                    {d.organizationDirect}: {unit.direct_person_count} ({percentText(unit.direct_exposure_share)})
                  </span>
                  <span className="tabular-nums text-muted-foreground">
                    {d.organizationSubtree}: {unit.subtree_person_count} ({percentText(unit.subtree_exposure_share)})
                  </span>
                </li>
              ))}
            </ul>
            <p className="text-sm text-muted-foreground" data-testid="placement-not-recorded">
              {d.organizationNotRecorded}: {placement.not_recorded_person_count} ({percentText(placement.not_recorded_exposure_share)})
            </p>
          </KpiSection>

          <KpiSection kpi="actual_workplace" title={d.workplaceTitle} hint={d.workplaceHint} wide>
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
              <CountList
                testId="workplace-non-determinable"
                items={workplaces.non_determinable.map((n) => ({
                  key: n.state,
                  text: (
                    <>
                      {d.workplaceNonDeterminable} — {nonDeterminableLabel(d, n.state)}: {n.person_count} ({percentText(n.exposure_share)})
                    </>
                  ),
                }))}
              />
            ) : null}
          </KpiSection>

          <KpiSection kpi="status_exposure" title={d.statusTitle} wide>
            {exposure(data.employment_status.status_exposure)}
          </KpiSection>
        </div>
      )}

      <div className="grid gap-4 lg:grid-cols-2">
        <KpiSection kpi="relationship_starts" title={d.flowsTitle}>
          <div className="grid gap-4 sm:grid-cols-2">
            <ScalarCard kpi="relationship_starts" label={d.startsLabel} value={flows.relationship_starts.event_count} hint={d.startsHint} />
            <ScalarCard kpi="relationship_ends" label={d.endsLabel} value={flows.relationship_ends.event_count} hint={d.endsHint} />
          </div>
          {flows.relationship_ends.event_only_event_count > 0 ? (
            <p className="text-sm text-muted-foreground" data-testid="ends-event-only">
              {d.endsEventOnly}: {flows.relationship_ends.event_only_event_count}
            </p>
          ) : null}
          {flows.relationship_ends.by_reason.length > 0 ? (
            <>
              <SubHeading>{d.endsByReason}</SubHeading>
              <CountList
                testId="ends-by-reason"
                items={flows.relationship_ends.by_reason.map((r) => ({
                  key: r.reason,
                  text: (
                    <>
                      {reasonLabel(d, r.reason)}: {r.event_count}
                    </>
                  ),
                }))}
              />
            </>
          ) : null}
        </KpiSection>

        <DataQualityPanel entries={data.data_quality} />
      </div>
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
        <p className="text-sm text-muted-foreground" data-testid="dq-none">
          {d.dataQualityNone}
        </p>
      ) : (
        <ul className="space-y-2" role="list">
          {findings.map((entry) => (
            <QualityFinding
              key={entry.code}
              code={entry.code}
              label={dataQualityLabel(d, entry.code)}
              counts={`${entry.person_count} ${d.persons}${entry.relationship_count !== null ? ` · ${entry.relationship_count} ${d.relationships}` : ''}`}
            />
          ))}
        </ul>
      )}
    </KpiSection>
  )
}
