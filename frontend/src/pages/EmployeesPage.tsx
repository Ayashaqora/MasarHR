import { PermissionGate } from '../features/auth/PermissionGate'
import { EmployeeSearchForm } from '../features/employees/EmployeeSearchForm'
import { RelationshipsList } from '../features/employees/RelationshipsList'
import { usePersonSearch } from '../features/employees/hooks'
import { useI18n } from '../i18n/context'
import { describeApiError } from '../shared/api/errorMessage'
import { HR_PERMISSIONS } from '../shared/security/permissions'
import { PageHeader } from '../shared/ui/PageHeader'
import { StatePanel } from '../shared/ui/StatePanel'

/**
 * S18 Employees discovery screen (spec §S18 flow §7/§10). Read-only: it never offers create,
 * edit or delete controls, only the national-ID search the backend actually supports and a link
 * into each found relationship's Employee 360 page.
 */
export function EmployeesPage() {
  const { messages } = useI18n()
  const { state, search } = usePersonSearch()

  return (
    <>
      <PageHeader title={messages.employees.title} description={messages.employees.intro} />
      <PermissionGate permission={HR_PERMISSIONS.personsView}>
        <EmployeeSearchForm onSearch={search} submitting={state.status === 'loading'} />

        {state.status === 'loading' ? (
          <StatePanel tone="loading" title={messages.employees.searching} />
        ) : null}

        {state.status === 'error' ? (
          <StatePanel
            tone="error"
            title={
              state.error.status === 404 ? messages.employees.notFound : messages.employees.searchFailed
            }
          >
            {state.error.status === 404 ? null : describeApiError(state.error, messages)}
          </StatePanel>
        ) : null}

        {state.status === 'success' ? (
          <section aria-labelledby="employee-search-result-heading">
            <h2 id="employee-search-result-heading" className="card__title">
              {messages.employees.resultHeading}
            </h2>
            <RelationshipsList person={state.data} />
          </section>
        ) : null}
      </PermissionGate>
    </>
  )
}
