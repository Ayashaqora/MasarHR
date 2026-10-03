import { ArrowLeft } from 'lucide-react'
import { Link } from 'react-router'
import { Button } from '@/components/ui/button'
import { useI18n } from '../../i18n/context'
import { describeApiError } from '../../shared/api/errorMessage'
import { DataTable } from '../../shared/ui/DataTable'
import { EmptyState } from '../../shared/ui/EmptyState'
import { Ltr } from '../../shared/ui/Ltr'
import { RetryButton } from '../../shared/ui/RetryButton'
import { StatePanel } from '../../shared/ui/StatePanel'
import { StatusBadge } from '../../shared/ui/StatusBadge'
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
    return <StatePanel tone="loading" title={messages.employees.relationshipsLoading} className="my-0" />
  }

  if (relationships.status === 'error') {
    return (
      <StatePanel
        tone="error"
        title={messages.employees.relationshipsFailed}
        className="my-0"
        action={<RetryButton onClick={relationships.retry} />}
      >
        {describeApiError(relationships.error, messages)}
      </StatePanel>
    )
  }

  if (relationships.data.length === 0) {
    return <EmptyState title={messages.employees.relationshipsEmpty} />
  }

  return (
    <DataTable
      caption={messages.employees.relationshipsTitle}
      rows={relationships.data}
      getKey={(relationship) => relationship.id}
      columns={[
        {
          header: messages.employees.employeeNumber,
          cell: (relationship) =>
            relationship.employee_number ? <Ltr>{relationship.employee_number}</Ltr> : messages.employees.noEmployeeNumber,
        },
        {
          header: messages.employees.employmentScheme,
          cell: (relationship) =>
            relationship.employee_number_scheme === 'PERMANENT'
              ? messages.employees.schemePermanent
              : messages.employees.schemeContract,
        },
        {
          header: messages.employees.effectiveFrom,
          cell: (relationship) => (relationship.effective_from ? <Ltr>{relationship.effective_from}</Ltr> : '—'),
        },
        {
          header: messages.employees.relationshipState,
          cell: (relationship) =>
            relationship.end_knowledge_state === 'KNOWN' ? (
              <StatusBadge status="inactive">{messages.employees.relationshipEnded}</StatusBadge>
            ) : (
              <StatusBadge status="active">{messages.employees.relationshipActive}</StatusBadge>
            ),
        },
        {
          header: messages.employees.actions,
          cell: (relationship) => (
            <Button asChild size="sm" variant="outline">
              <Link to={`/employees/${person.id}/relationships/${relationship.id}`}>
                {messages.employees.view360}
                <ArrowLeft aria-hidden="true" className="rtl:rotate-0 ltr:rotate-180" />
              </Link>
            </Button>
          ),
        },
      ]}
    />
  )
}
