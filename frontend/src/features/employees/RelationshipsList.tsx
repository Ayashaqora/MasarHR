import { Link } from 'react-router'
import { useI18n } from '../../i18n/context'
import { describeApiError } from '../../shared/api/errorMessage'
import { StatePanel } from '../../shared/ui/StatePanel'
import { useEmploymentRelationships } from './hooks'
import type { Person } from './api'

/**
 * Shown after a successful person search (spec §S18 flow §7). A Person is not itself the
 * Employee 360 subject — the employment story lives on its Employment Relationship(s) (S18
 * discovery §6) — so this lists every relationship the found Person has and links each to its
 * own Employee 360 page, exactly like S09's own person→relationship nesting.
 */
export function RelationshipsList({ person }: { person: Person }) {
  const { messages } = useI18n()
  const relationships = useEmploymentRelationships(person.id)

  if (relationships.status === 'loading') {
    return <StatePanel tone="loading" title={messages.employees.relationshipsLoading} />
  }

  if (relationships.status === 'error') {
    return (
      <StatePanel
        tone="error"
        title={messages.employees.relationshipsFailed}
        action={
          <button type="button" className="button" onClick={relationships.retry}>
            {messages.systemStatus.retry}
          </button>
        }
      >
        {describeApiError(relationships.error, messages)}
      </StatePanel>
    )
  }

  if (relationships.data.length === 0) {
    return <StatePanel tone="success" title={messages.employees.relationshipsEmpty} />
  }

  return (
    <table className="data-table">
      <caption className="sr-only">{messages.employees.relationshipsTitle}</caption>
      <thead>
        <tr>
          <th scope="col">{messages.employees.employeeNumber}</th>
          <th scope="col">{messages.employees.employmentScheme}</th>
          <th scope="col">{messages.employees.effectiveFrom}</th>
          <th scope="col">{messages.employees.relationshipState}</th>
          <th scope="col">{messages.employees.actions}</th>
        </tr>
      </thead>
      <tbody>
        {relationships.data.map((relationship) => (
          <tr key={relationship.id}>
            <td>{relationship.employee_number ?? messages.employees.noEmployeeNumber}</td>
            <td>
              {relationship.employee_number_scheme === 'PERMANENT'
                ? messages.employees.schemePermanent
                : messages.employees.schemeContract}
            </td>
            <td>{relationship.effective_from ?? '—'}</td>
            <td>
              {relationship.end_knowledge_state === 'KNOWN'
                ? messages.employees.relationshipEnded
                : messages.employees.relationshipActive}
            </td>
            <td>
              <Link
                className="button button--small"
                to={`/employees/${person.id}/relationships/${relationship.id}`}
              >
                {messages.employees.view360}
              </Link>
            </td>
          </tr>
        ))}
      </tbody>
    </table>
  )
}
