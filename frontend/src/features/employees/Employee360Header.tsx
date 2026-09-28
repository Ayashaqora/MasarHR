import { useI18n } from '../../i18n/context'
import type { ApiResourceState } from '../../shared/hooks/useApiResource'
import type {
  ActualWorkplace,
  EmploymentRelationship,
  EmploymentStatusDetail,
  EmploymentStatusPeriod,
  Person,
} from './api'

/**
 * Compact identity/employment header (spec §S18 §11), always visible above the tabs. Only fields
 * the backend actually supports are shown — no profile photo, email, phone, address, manager or
 * salary (none exist in the domain; spec §11 forbids fabricating them), and no employee "name" —
 * S09's Person resource has no name field in v1 (spec §S18 discovery §4), a disclosed, deferred
 * capability gap rather than an invented one.
 */
export function Employee360Header({
  person,
  relationship,
  statusPeriods,
  actualWorkplace,
  statusCatalog,
  unitNames,
}: {
  person: Person
  relationship: EmploymentRelationship
  statusPeriods: ApiResourceState<EmploymentStatusPeriod[]>
  actualWorkplace: ApiResourceState<ActualWorkplace>
  statusCatalog: ApiResourceState<EmploymentStatusDetail[]>
  unitNames: { status: 'loading' | 'ready'; names: Record<string, string> }
}) {
  const { messages, locale } = useI18n()

  const openStatusPeriod =
    statusPeriods.status === 'success' ? statusPeriods.data.find((period) => period.effective_to === null) : null

  const statusDetail =
    openStatusPeriod && statusCatalog.status === 'success'
      ? statusCatalog.data.find((detail) => detail.id === openStatusPeriod.status_detail_id)
      : null

  const statusLabel = statusDetail ? (locale === 'ar' ? statusDetail.name_ar : statusDetail.name_en) : null

  const actualWorkplaceName =
    actualWorkplace.status === 'success' && actualWorkplace.data.organizational_unit_id
      ? (unitNames.names[actualWorkplace.data.organizational_unit_id] ?? null)
      : null

  return (
    <section className="card employee-360-header" aria-labelledby="employee-360-header-heading">
      <h2 id="employee-360-header-heading" className="card__title">
        {messages.employee360.headerTitle}
      </h2>
      <dl className="description-list">
        <div className="description-list__row">
          <dt>{messages.employee360.nationalId}</dt>
          <dd>{person.national_id}</dd>
        </div>
        <div className="description-list__row">
          <dt>{messages.employees.employeeNumber}</dt>
          <dd>{relationship.employee_number ?? messages.employees.noEmployeeNumber}</dd>
        </div>
        <div className="description-list__row">
          <dt>{messages.employees.employmentScheme}</dt>
          <dd>
            {relationship.employee_number_scheme === 'PERMANENT'
              ? messages.employees.schemePermanent
              : messages.employees.schemeContract}
          </dd>
        </div>
        <div className="description-list__row">
          <dt>{messages.employees.relationshipState}</dt>
          <dd>
            {relationship.end_knowledge_state === 'KNOWN'
              ? messages.employees.relationshipEnded
              : messages.employees.relationshipActive}
          </dd>
        </div>
        <div className="description-list__row">
          <dt>{messages.employee360.currentStatus}</dt>
          <dd>
            {statusPeriods.status === 'loading' || statusCatalog.status === 'loading'
              ? messages.employee360.loading
              : (statusLabel ?? messages.employee360.noOpenStatusPeriod)}
          </dd>
        </div>
        <div className="description-list__row">
          <dt>{messages.employee360.actualWorkplace}</dt>
          <dd>
            {actualWorkplace.status === 'loading' || unitNames.status === 'loading'
              ? messages.employee360.loading
              : (actualWorkplaceName ?? messages.employee360.noActualWorkplace)}
          </dd>
        </div>
      </dl>
    </section>
  )
}
