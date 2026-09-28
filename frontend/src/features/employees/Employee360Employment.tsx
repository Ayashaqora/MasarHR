import { useI18n } from '../../i18n/context'
import type { EmploymentRelationship } from './api'

/** Employment tab (spec §S18 §12.B): the full Employment Relationship record, read-only. */
export function Employee360Employment({ relationship }: { relationship: EmploymentRelationship }) {
  const { messages } = useI18n()

  return (
    <div className="tab-panel-content">
      <section className="card">
        <dl className="description-list">
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
            <dt>{messages.employees.effectiveFrom}</dt>
            <dd>{relationship.effective_from ?? '—'}</dd>
          </div>
          <div className="description-list__row">
            <dt>{messages.employee360.effectiveTo}</dt>
            <dd>{relationship.effective_to ?? messages.employee360.openEnded}</dd>
          </div>
          <div className="description-list__row">
            <dt>{messages.employees.relationshipState}</dt>
            <dd>
              {relationship.end_knowledge_state === 'KNOWN'
                ? messages.employees.relationshipEnded
                : relationship.end_knowledge_state === 'UNKNOWN'
                  ? messages.employee360.endUnknown
                  : messages.employees.relationshipActive}
            </dd>
          </div>
          {relationship.end_knowledge_state === 'KNOWN' ? (
            <div className="description-list__row">
              <dt>{messages.employee360.endedTerminally}</dt>
              <dd>{relationship.ended_terminally ? messages.employee360.yes : messages.employee360.no}</dd>
            </div>
          ) : null}
        </dl>
      </section>
    </div>
  )
}
