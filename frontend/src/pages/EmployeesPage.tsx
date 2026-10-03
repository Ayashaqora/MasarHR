import { IdCard } from 'lucide-react'
import { useState } from 'react'
import { PermissionGate } from '../features/auth/PermissionGate'
import { EmployeeSearchForm } from '../features/employees/EmployeeSearchForm'
import { RelationshipsList } from '../features/employees/RelationshipsList'
import { usePersonSearch } from '../features/employees/hooks'
import { useI18n } from '../i18n/context'
import { describeApiError } from '../shared/api/errorMessage'
import { HR_PERMISSIONS } from '../shared/security/permissions'
import { DefinitionItem, DefinitionList } from '../shared/ui/DefinitionList'
import { EmptyState } from '../shared/ui/EmptyState'
import { Ltr } from '../shared/ui/Ltr'
import { PageHeader } from '../shared/ui/PageHeader'
import { RetryButton } from '../shared/ui/RetryButton'
import { SectionCard } from '../shared/ui/SectionCard'
import { StatePanel } from '../shared/ui/StatePanel'

/**
 * S18 Employees discovery screen (spec §S18 flow §7/§10). Read-only: it never offers create,
 * edit or delete controls, only the national-ID search the backend actually supports and a link
 * into each found relationship's Employee 360 page.
 */
export function EmployeesPage() {
  const { messages } = useI18n()
  const { state, search } = usePersonSearch()
  const [lastQuery, setLastQuery] = useState('')

  const runSearch = (nationalId: string) => {
    setLastQuery(nationalId)
    search(nationalId)
  }

  return (
    <>
      <PageHeader title={messages.employees.title} description={messages.employees.intro} />
      <PermissionGate permission={HR_PERMISSIONS.personsView}>
        <div className="mx-auto max-w-4xl space-y-6">
          <EmployeeSearchForm onSearch={runSearch} submitting={state.status === 'loading'} />

          {state.status === 'idle' ? (
            <EmptyState title={messages.employees.initialTitle}>{messages.employees.initialDescription}</EmptyState>
          ) : null}

          {state.status === 'loading' ? <StatePanel tone="loading" title={messages.employees.searching} className="my-0" /> : null}

          {state.status === 'error' ? (
            state.error.status === 403 ? (
              <StatePanel tone="error" title={messages.securityShared.unauthorizedTitle} className="my-0">
                {messages.securityShared.unauthorizedDescription}
              </StatePanel>
            ) : (
              <StatePanel
                tone="error"
                className="my-0"
                title={state.error.status === 404 ? messages.employees.notFound : messages.employees.searchFailed}
                action={state.error.status === 404 ? undefined : <RetryButton onClick={() => runSearch(lastQuery)} />}
              >
                {state.error.status === 404 ? null : describeApiError(state.error, messages)}
              </StatePanel>
            )
          ) : null}

          {state.status === 'success' ? (
            <SectionCard
              headingId="employee-search-result-heading"
              title={messages.employees.resultHeading}
              contentClassName="space-y-5"
            >
              <div className="flex items-start gap-3 rounded-md bg-muted/60 p-4">
                <IdCard aria-hidden="true" className="mt-0.5 size-5 shrink-0 text-muted-foreground" />
                <DefinitionList className="flex-1">
                  <DefinitionItem label={messages.employee360.fullNameAr}>
                    {state.data.full_name_ar ?? messages.employee360.notRecorded}
                  </DefinitionItem>
                  <DefinitionItem label={messages.employee360.nationalId}>
                    <Ltr>{state.data.national_id}</Ltr>
                  </DefinitionItem>
                </DefinitionList>
              </div>
              <RelationshipsList person={state.data} />
            </SectionCard>
          ) : null}
        </div>
      </PermissionGate>
    </>
  )
}
